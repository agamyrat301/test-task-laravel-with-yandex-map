<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Review;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Получает данные организации и все отзывы с Яндекс.Карт без официального API.
 *
 * Стратегия:
 * 1. GET страницы организации → устанавливаем сессионные cookie и вытаскиваем
 *    CSRF-токен + начальные данные из embedded JSON (<script id="store-prefetch">).
 * 2. Итерируем внутренний API Яндекса (/maps/api/business/fetchReviews) с теми же
 *    cookie и CSRF-токеном — так браузер и получает отзывы при прокрутке.
 *
 * Почему не headless-браузер:
 *   Headless (Puppeteer/Playwright) надёжнее при агрессивной защите, но требует
 *   Node.js-процесса рядом с PHP и существенно медленнее. Для данного задания
 *   HTTP-подход достаточен: Яндекс отдаёт embedded JSON для SEO-краулеров, а
 *   сессионные cookie + заголовки, имитирующие реальный браузер, проходят
 *   базовую защиту. Если Яндекс начнёт выдавать каптчу — см. README.md.
 */
class YandexMapsService
{
    private const ORG_URL_RE       = '#^https?://(?:yandex\.(?:ru|com|kz|by|uz)|maps\.yandex\.ru)/maps/org/[^/]+/(\d+)#i';
    private const REVIEWS_PER_BATCH = 60;
    private const MAX_REVIEWS       = 700;
    private const DELAY_US          = 600_000; // 0.6 сек между запросами

    private const BROWSER_HEADERS = [
        'User-Agent'                => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept-Language'           => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
        'Accept-Encoding'           => 'gzip, deflate, br',
        'Cache-Control'             => 'no-cache',
        'Pragma'                    => 'no-cache',
        'Connection'                => 'keep-alive',
        'DNT'                       => '1',
        'Sec-Ch-Ua'                 => '"Not_A Brand";v="8", "Chromium";v="120", "Google Chrome";v="120"',
        'Sec-Ch-Ua-Mobile'          => '?0',
        'Sec-Ch-Ua-Platform'        => '"Windows"',
        'Upgrade-Insecure-Requests' => '1',
    ];

    private Client    $http;
    private CookieJar $jar;

    public function __construct()
    {
        $this->jar  = new CookieJar();
        $this->http = new Client([
            'cookies'         => $this->jar,
            'timeout'         => 30,
            'connect_timeout' => 10,
            'allow_redirects' => ['max' => 5, 'track_redirects' => true],
            'verify'          => false,
            'http_errors'     => false,
        ]);
    }

    // -------------------------------------------------------------------------
    // Public interface
    // -------------------------------------------------------------------------

    public function validateUrl(string $url): bool
    {
        return (bool) preg_match(self::ORG_URL_RE, $url);
    }

    public function extractOrgId(string $url): ?string
    {
        if (preg_match(self::ORG_URL_RE, $url, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Полная синхронизация: данные организации + все отзывы.
     */
    public function sync(Organization $org): void
    {
        $orgId = $org->yandex_org_id;

        // 1) Загружаем страницу, устанавливаем сессию, парсим embedded JSON
        $pageData = $this->initSession($orgId);

        // 2) Сохраняем данные организации
        $org->update([
            'name'           => $pageData['name'] ?? $org->name,
            'address'        => $pageData['address'] ?? $org->address,
            'rating'         => $pageData['rating'],
            'ratings_count'  => $pageData['ratings_count'],
            'reviews_count'  => $pageData['reviews_count'],
            'last_synced_at' => now(),
        ]);

        // 3) Получаем все отзывы через внутренний API
        $allReviews = $this->fetchAllReviews($orgId, $pageData['csrf_token']);

        foreach ($allReviews as $raw) {
            Review::updateOrCreate(
                ['yandex_review_id' => $raw['id']],
                [
                    'organization_id' => $org->id,
                    'author'          => $raw['author'],
                    'rating'          => $raw['rating'],
                    'text'            => $raw['text'],
                    'reviewed_at'     => $raw['date'],
                ]
            );
        }
    }

    // -------------------------------------------------------------------------
    // Step 1 — Page fetch & session init
    // -------------------------------------------------------------------------

    /**
     * Делает GET на страницу отзывов организации.
     * Цели:
     *   - записать сессионные cookie в $this->jar (yandexuid, Session_id, i и др.)
     *   - извлечь CSRF-токен (нужен для API-запросов)
     *   - разобрать embedded JSON с данными организации
     */
    private function initSession(string $orgId): array
    {
        $url = "https://yandex.ru/maps/org/{$orgId}/reviews/";

        $response = $this->http->get($url, [
            'headers' => array_merge(self::BROWSER_HEADERS, [
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Sec-Fetch-Dest'  => 'document',
                'Sec-Fetch-Mode'  => 'navigate',
                'Sec-Fetch-Site'  => 'none',
                'Sec-Fetch-User'  => '?1',
            ]),
        ]);

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new RuntimeException("Яндекс.Карты вернул HTTP {$status} для org {$orgId}.");
        }

        $html = (string) $response->getBody();

        if (empty($html)) {
            throw new RuntimeException('Пустой ответ от Яндекс.Карт.');
        }

        return $this->parseHtml($html);
    }

    /**
     * Разбирает HTML страницы: ищет embedded JSON, CSRF-токен, данные организации.
     */
    private function parseHtml(string $html): array
    {
        $result = [
            'csrf_token'    => '',
            'name'          => null,
            'address'       => null,
            'rating'        => null,
            'ratings_count' => 0,
            'reviews_count' => 0,
        ];

        // ----- CSRF-токен -----
        // Паттерн 1: "csrfToken":"<value>" в любом месте HTML/JSON
        if (preg_match('/"csrfToken"\s*:\s*"([^"]{10,})"/', $html, $m)) {
            $result['csrf_token'] = $m[1];
        }
        // Паттерн 2: <meta name="csrf-token" content="...">
        if (!$result['csrf_token']
            && preg_match('/<meta[^>]+name=["\']csrf-token["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m)
        ) {
            $result['csrf_token'] = $m[1];
        }

        // ----- Embedded JSON -----
        // Яндекс кладёт состояние SPA в <script id="store-prefetch" type="application/json">
        if (preg_match(
            '/<script[^>]+id=["\']store-prefetch["\'][^>]*>\s*(\{.+?\})\s*<\/script>/s',
            $html,
            $m
        )) {
            $state = json_decode($m[1], true);
            if (is_array($state)) {
                $this->extractOrgData($state, $result);
            }
        }

        // Fallback: window.__REDUX_STATE__ = {...}
        if (!$result['name']
            && preg_match('/window\.__REDUX_STATE__\s*=\s*(\{.+?\});\s*(?:<\/script>|window\.)/s', $html, $m)
        ) {
            $state = json_decode($m[1], true);
            if (is_array($state)) {
                $this->extractOrgData($state, $result);
            }
        }

        // Fallback: OpenGraph + Schema.org microdata (минимум для отображения)
        if (!$result['name']) {
            if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\'](.*?)["\']/', $html, $m)) {
                $result['name'] = html_entity_decode($m[1]);
            }
            if (preg_match('/"ratingValue"\s*:\s*"?([\d.]+)"?/', $html, $m)) {
                $result['rating'] = (float) $m[1];
            }
        }

        return $result;
    }

    /**
     * Рекурсивно вытаскивает нужные поля из JSON-состояния страницы.
     * Яндекс меняет структуру JSON — поэтому ищем по ключам, а не по пути.
     */
    private function extractOrgData(array $state, array &$out): void
    {
        // CSRF может быть глубоко внутри config-блока
        if (!$out['csrf_token']) {
            $csrf = $this->findDeep($state, 'csrfToken');
            if (is_string($csrf) && strlen($csrf) > 5) {
                $out['csrf_token'] = $csrf;
            }
        }

        // Название и адрес
        $out['name']    = $out['name']    ?? $this->findDeep($state, 'name');
        $out['address'] = $out['address'] ?? $this->findDeep($state, 'address');

        // Рейтинг — блок может называться rating / ratingValue
        $ratingBlock = $this->findDeep($state, 'rating');
        if (is_array($ratingBlock)) {
            // value — средний балл
            if (isset($ratingBlock['value'])) {
                $out['rating'] = (float) $ratingBlock['value'];
            }
            // votes / count — количество оценок (включая без текста)
            $out['ratings_count'] = (int) (
                $ratingBlock['votes']        ??
                $ratingBlock['count']        ??
                $ratingBlock['ratingsCount'] ??
                0
            );
            // reviews — только отзывы (с текстом)
            $out['reviews_count'] = (int) (
                $ratingBlock['reviews']      ??
                $ratingBlock['reviewsCount'] ??
                $ratingBlock['textCount']    ??
                0
            );
        }

        // Если reviews_count не нашли в rating-блоке — ищем отдельно
        if (!$out['reviews_count']) {
            $cnt = $this->findDeep($state, 'reviewsCount')
                ?? $this->findDeep($state, 'totalCount')
                ?? $this->findDeep($state, 'total');
            if (is_int($cnt) || (is_string($cnt) && ctype_digit($cnt))) {
                $out['reviews_count'] = (int) $cnt;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Step 2 — Reviews API pagination
    // -------------------------------------------------------------------------

    /**
     * Собирает ВСЕ отзывы, итерируя внутренний API Яндекса.
     *
     * Яндекс подгружает отзывы при прокрутке через XHR-запрос к
     * /maps/api/business/fetchReviews. Мы делаем те же запросы,
     * передавая сессионные cookie из $this->jar и CSRF-токен из HTML.
     */
    private function fetchAllReviews(string $orgId, string $csrfToken): array
    {
        $all   = [];
        $from  = 0;

        while ($from < self::MAX_REVIEWS) {
            usleep(self::DELAY_US);

            $batch = $this->fetchBatch($orgId, $from, self::REVIEWS_PER_BATCH, $csrfToken);

            if (empty($batch['reviews'])) {
                break; // Яндекс вернул пустую страницу — дошли до конца
            }

            $all  = array_merge($all, $batch['reviews']);
            $from += self::REVIEWS_PER_BATCH;

            // Если пришло меньше батча — это последняя страница
            if (count($batch['reviews']) < self::REVIEWS_PER_BATCH) {
                break;
            }
        }

        return $all;
    }

    private function fetchBatch(string $orgId, int $from, int $limit, string $csrfToken): array
    {
        try {
            $response = $this->http->get('https://yandex.ru/maps/api/business/fetchReviews', [
                'headers' => array_merge(self::BROWSER_HEADERS, [
                    'Accept'           => 'application/json, text/javascript, */*; q=0.01',
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Referer'          => "https://yandex.ru/maps/org/{$orgId}/reviews/",
                    'Sec-Fetch-Dest'   => 'empty',
                    'Sec-Fetch-Mode'   => 'cors',
                    'Sec-Fetch-Site'   => 'same-origin',
                ]),
                'query' => array_filter([
                    'businessId' => $orgId,
                    'from'       => $from,
                    'limit'      => $limit,
                    'lang'       => 'ru_RU',
                    'csrfToken'  => $csrfToken ?: null,
                ]),
            ]);

            $status = $response->getStatusCode();

            if ($status === 429) {
                Log::warning("YandexMaps: rate limit hit (from={$from}), sleeping 5s");
                sleep(5);
                // Один повтор
                return $this->fetchBatch($orgId, $from, $limit, $csrfToken);
            }

            if ($status !== 200) {
                Log::warning("YandexMaps: fetchBatch HTTP {$status} (from={$from})");
                return ['reviews' => []];
            }

            $body = (string) $response->getBody();
            $json = json_decode($body, true);

            if (!is_array($json)) {
                Log::warning("YandexMaps: non-JSON response at from={$from}", ['body' => substr($body, 0, 200)]);
                return ['reviews' => []];
            }

            return $this->parseBatch($json);

        } catch (GuzzleException $e) {
            Log::warning("YandexMaps: fetchBatch exception at from={$from}", ['error' => $e->getMessage()]);
            return ['reviews' => []];
        }
    }

    private function parseBatch(array $json): array
    {
        // Ищем массив отзывов — ключ может быть "reviews", "data", "items"
        $list = null;
        foreach (['reviews', 'data', 'items', 'list'] as $key) {
            $found = $this->findDeep($json, $key);
            if (is_array($found) && !empty($found) && isset($found[0])) {
                $list = $found;
                break;
            }
        }

        if ($list === null) {
            return ['reviews' => []];
        }

        $reviews = [];
        foreach ($list as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $review = $this->normalizeReview($raw);
            if ($review !== null) {
                $reviews[] = $review;
            }
        }

        return ['reviews' => $reviews];
    }

    /**
     * Приводит отзыв из ответа API к единому формату.
     * Яндекс менял структуру несколько раз — отсюда все варианты.
     */
    private function normalizeReview(array $raw): ?array
    {
        // ID
        $id = $raw['id']
            ?? $raw['reviewId']
            ?? $raw['yandexUid']
            ?? null;

        if (!$id) {
            return null;
        }

        // Автор
        $author = $raw['author']['name']
            ?? $raw['author']['publicName']
            ?? $raw['authorName']
            ?? $raw['authorPublicName']
            ?? $raw['user']['name']
            ?? 'Аноним';

        // Оценка (1–5)
        $rating = (int) ($raw['rating'] ?? $raw['stars'] ?? $raw['grade'] ?? 0);

        // Текст (может отсутствовать — это просто оценка без отзыва)
        $text = $raw['text'] ?? $raw['body'] ?? $raw['comment'] ?? null;
        if ($text !== null) {
            $text = trim(strip_tags((string) $text)) ?: null;
        }

        // Дата публикации
        $dateRaw = $raw['updatedTime']
            ?? $raw['createdTime']
            ?? $raw['date']
            ?? $raw['publishedTime']
            ?? $raw['timestamp']
            ?? null;

        if (is_numeric($dateRaw)) {
            $date = date('Y-m-d', (int) $dateRaw);
        } elseif (is_string($dateRaw) && strlen($dateRaw) >= 10) {
            $date = substr($dateRaw, 0, 10);
        } else {
            $date = now()->toDateString();
        }

        return [
            'id'     => (string) $id,
            'author' => (string) $author,
            'rating' => $rating,
            'text'   => $text,
            'date'   => $date,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Рекурсивный поиск значения по ключу в многомерном массиве.
     * Возвращает первое найденное значение.
     */
    private function findDeep(array $data, string $key): mixed
    {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->findDeep($value, $key);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
