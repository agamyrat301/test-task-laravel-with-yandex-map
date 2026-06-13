<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Review;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class YandexMapsService
{
    private const REVIEWS_PER_REQUEST = 60;

    private const HEADERS = [
        'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
        'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Referer'         => 'https://yandex.ru/maps/',
    ];

    public function validateUrl(string $url): bool
    {
        return (bool) preg_match(
            '#^https?://(yandex\.(ru|com|kz|by|uz)|maps\.yandex\.ru)/maps/org/[^/]+/\d+#i',
            $url
        );
    }

    public function extractOrgId(string $url): ?string
    {
        if (preg_match('#/maps/org/[^/]+/(\d+)#', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    public function sync(Organization $org): void
    {
        $orgId = $org->yandex_org_id;

        $info = $this->fetchOrgInfo($orgId);

        $org->update([
            'name'          => $info['name'],
            'address'       => $info['address'] ?? null,
            'rating'        => $info['rating'],
            'ratings_count' => $info['ratings_count'],
            'reviews_count' => $info['reviews_count'],
            'last_synced_at' => now(),
        ]);

        $reviews = $this->fetchAllReviews($orgId);

        foreach ($reviews as $raw) {
            Review::updateOrCreate(
                ['yandex_review_id' => $raw['id']],
                [
                    'organization_id' => $org->id,
                    'author'          => $raw['author'],
                    'rating'          => $raw['rating'],
                    'text'            => $raw['text'] ?? null,
                    'reviewed_at'     => $raw['date'],
                ]
            );
        }
    }

    // -------------------------------------------------------------------------

    private function fetchOrgInfo(string $orgId): array
    {
        $url = "https://yandex.ru/maps/api/business/fetchById?businessId={$orgId}&lang=ru_RU";

        $response = Http::withHeaders(self::HEADERS)
            ->withOptions(['verify' => false])
            ->get($url);

        if ($response->failed()) {
            // Fallback: parse from the HTML page
            return $this->fetchOrgInfoFromHtml($orgId);
        }

        $data = $response->json();

        return $this->extractOrgInfoFromApiResponse($data);
    }

    private function fetchOrgInfoFromHtml(string $orgId): array
    {
        $pageUrl  = "https://yandex.ru/maps/org/{$orgId}/";
        $response = Http::withHeaders(self::HEADERS)
            ->withOptions(['verify' => false])
            ->get($pageUrl);

        if ($response->failed()) {
            throw new RuntimeException('Не удалось получить данные с Яндекс.Карт.');
        }

        $html = $response->body();

        return $this->parseOrgInfoFromHtml($html);
    }

    private function parseOrgInfoFromHtml(string $html): array
    {
        // Яндекс.Карты вшивают состояние приложения в тег <script id="state">
        // Пробуем несколько известных паттернов.

        $json = null;

        // Паттерн 1: window.__REDUX_STATE__ = {...};
        if (preg_match('/window\.__REDUX_STATE__\s*=\s*(\{.+?\});\s*<\/script>/s', $html, $m)) {
            $json = $m[1];
        }

        // Паттерн 2: <script id="initial-state" ...>...
        if (!$json && preg_match('/<script[^>]+id=["\']initial-state["\'][^>]*>(.+?)<\/script>/s', $html, $m)) {
            $json = $m[1];
        }

        // Паттерн 3: data-bem или другие вложения
        if (!$json && preg_match('/"rating"\s*:\s*\{[^}]+\}/', $html, $m)) {
            // Частичный результат, вытащим только нужные поля ниже
        }

        if ($json) {
            $data = json_decode($json, true);
            if ($data) {
                return $this->extractOrgInfoFromApiResponse($data);
            }
        }

        // Последний вариант — вытащить Open Graph / schema.org разметку
        return $this->parseSchemaOrg($html);
    }

    private function parseSchemaOrg(string $html): array
    {
        $name    = null;
        $address = null;
        $rating  = null;

        if (preg_match('/<meta\s+property=["\']og:title["\']\s+content=["\'](.*?)["\']/i', $html, $m)) {
            $name = html_entity_decode($m[1]);
        }

        if (preg_match('/"ratingValue"\s*:\s*"?([\d.]+)"?/', $html, $m)) {
            $rating = (float) $m[1];
        }

        if (!$name) {
            throw new RuntimeException('Не удалось извлечь данные организации из страницы.');
        }

        return [
            'name'          => $name,
            'address'       => $address,
            'rating'        => $rating,
            'ratings_count' => 0,
            'reviews_count' => 0,
        ];
    }

    private function extractOrgInfoFromApiResponse(array $data): array
    {
        // Ищем данные о компании рекурсивно — структура может различаться
        $company = $this->findKey($data, 'businessInfo')
            ?? $this->findKey($data, 'business')
            ?? $this->findKey($data, 'organization')
            ?? [];

        $ratingData = $this->findKey($data, 'rating') ?? $this->findKey($company, 'rating') ?? [];

        return [
            'name'          => $this->findKey($company, 'name') ?? $this->findKey($data, 'name') ?? 'Неизвестно',
            'address'       => $this->findKey($company, 'address') ?? $this->findKey($data, 'address'),
            'rating'        => isset($ratingData['value']) ? (float) $ratingData['value'] : null,
            'ratings_count' => (int) ($ratingData['votes'] ?? $ratingData['ratingsCount'] ?? 0),
            'reviews_count' => (int) ($ratingData['reviews'] ?? $ratingData['reviewsCount'] ?? 0),
        ];
    }

    // -------------------------------------------------------------------------

    private function fetchAllReviews(string $orgId): array
    {
        $all    = [];
        $from   = 0;
        $total  = PHP_INT_MAX;

        while ($from < $total) {
            $batch = $this->fetchReviewsBatch($orgId, $from, self::REVIEWS_PER_REQUEST);

            if (empty($batch['reviews'])) {
                break;
            }

            $all   = array_merge($all, $batch['reviews']);
            $total = $batch['total'] ?? count($all);
            $from += self::REVIEWS_PER_REQUEST;

            // Небольшая пауза, чтобы не попасть под rate-limit
            usleep(300_000);
        }

        return $all;
    }

    private function fetchReviewsBatch(string $orgId, int $from, int $limit): array
    {
        $url = 'https://yandex.ru/maps/api/business/fetchReviews';

        $response = Http::withHeaders(array_merge(self::HEADERS, [
            'Accept'           => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ]))->withOptions(['verify' => false])
            ->get($url, [
                'businessId' => $orgId,
                'from'       => $from,
                'limit'      => $limit,
                'lang'       => 'ru_RU',
            ]);

        if ($response->failed()) {
            // Пробуем альтернативный endpoint
            return $this->fetchReviewsBatchAlt($orgId, $from, $limit);
        }

        return $this->parseReviewsResponse($response->json());
    }

    private function fetchReviewsBatchAlt(string $orgId, int $from, int $limit): array
    {
        // Альтернативный endpoint, используемый новым фронтендом Яндекс.Карт
        $url = "https://yandex.ru/maps/org/{$orgId}/reviews/";

        $response = Http::withHeaders(array_merge(self::HEADERS, [
            'Accept' => 'application/json',
        ]))->withOptions(['verify' => false])
            ->get($url, [
                'ajax'  => 1,
                'from'  => $from,
                'limit' => $limit,
            ]);

        if ($response->failed()) {
            return ['reviews' => [], 'total' => 0];
        }

        return $this->parseReviewsResponse($response->json() ?? []);
    }

    private function parseReviewsResponse(array $data): array
    {
        $rawReviews = $this->findKey($data, 'reviews') ?? [];
        $total      = (int) ($this->findKey($data, 'total') ?? count($rawReviews));

        $reviews = [];
        foreach ($rawReviews as $raw) {
            $review = $this->normalizeReview($raw);
            if ($review) {
                $reviews[] = $review;
            }
        }

        return ['reviews' => $reviews, 'total' => $total];
    }

    private function normalizeReview(array $raw): ?array
    {
        // Яндекс может возвращать отзывы в разных форматах
        $id = $raw['id'] ?? $raw['reviewId'] ?? null;
        if (!$id) {
            return null;
        }

        $author = $raw['author']['name']
            ?? $raw['authorName']
            ?? $raw['user']['name']
            ?? 'Аноним';

        $rating = (int) ($raw['rating'] ?? $raw['stars'] ?? 0);

        $text = $raw['text'] ?? $raw['body'] ?? null;

        $date = $raw['updatedTime']
            ?? $raw['createdTime']
            ?? $raw['date']
            ?? now()->toDateString();

        return [
            'id'     => (string) $id,
            'author' => $author,
            'rating' => $rating,
            'text'   => $text,
            'date'   => substr($date, 0, 10),
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Рекурсивно ищет ключ в многомерном массиве.
     */
    private function findKey(array $data, string $key): mixed
    {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $result = $this->findKey($value, $key);
                if ($result !== null) {
                    return $result;
                }
            }
        }

        return null;
    }
}
