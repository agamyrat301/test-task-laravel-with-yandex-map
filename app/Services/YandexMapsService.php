<?php

namespace App\Services;

use App\Exceptions\YandexParseException;
use App\Models\Organization;
use App\Models\Review;
use Illuminate\Support\Facades\Log;

/**
 * Получает данные организации и все отзывы с Яндекс.Карт без официального API.
 *
 * Стратегия (Puppeteer-based):
 *   1. Node.js-скрипт (scripts/yandex-scraper.js) открывает страницу организации
 *      через настоящий браузер Chromium — это позволяет пройти TLS-fingerprinting
 *      и SmartCaptcha Яндекса, которые блокируют curl/Guzzle на уровне хендшейка.
 *   2. Данные организации берутся из серверных OG-тегов (всегда есть в HTML).
 *   3. Отзывы извлекаются из Schema.org-разметки (.business-review-view) по мере
 *      прокрутки сайдбара: браузер подгружает пачки по ~200 при скролле вниз.
 *   4. Полученный JSON передаётся в PHP через stdout.
 *
 * Почему Puppeteer, а не Guzzle:
 *   Guzzle/cURL имеют уникальный TLS Client Hello, который Яндекс SmartCaptcha
 *   детектирует независимо от User-Agent и заголовков. Реальный Chromium
 *   проходит все проверки без капчи.
 */
class YandexMapsService
{
    // Matches both /maps/org/name/id and /maps/213/city/org/name/id (city-code variant)
    private const ORG_URL_RE = '#^https?://(?:yandex\.(?:ru|com|kz|by|uz|am|az)|maps\.yandex\.ru)/maps/(?:\d+/[^/]+/)?org/[^/]+/(\d+)#i';

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
        $data = $this->runScraper($org->yandex_org_id);

        // Обновляем данные организации
        $org->update([
            'name'           => $data['org']['name']          ?? $org->name,
            'address'        => $data['org']['address']        ?? $org->address,
            'rating'         => $data['org']['rating'],
            'ratings_count'  => $data['org']['ratings_count']  ?? 0,
            'reviews_count'  => $data['org']['reviews_count']  ?? 0,
            'last_synced_at' => now(),
        ]);

        // Сохраняем отзывы (updateOrCreate — безопасно для повторной синхронизации)
        foreach ($data['reviews'] as $raw) {
            $review = $this->normalizeReview($raw);
            if ($review === null) {
                continue;
            }

            Review::updateOrCreate(
                ['organization_id' => $org->id, 'yandex_review_id' => $review['id']],
                [
                    'author'          => $review['author'],
                    'rating'          => $review['rating'],
                    'text'            => $review['text'],
                    'reviewed_at'     => $review['date'],
                ]
            );
        }
    }

    // -------------------------------------------------------------------------
    // Scraper invocation
    // -------------------------------------------------------------------------

    /**
     * Запускает Node.js-скрапер и возвращает распарсенный JSON.
     * stdout → данные, stderr → ошибки, exit code 1 → исключение.
     *
     * @throws YandexParseException
     */
    private function runScraper(string $orgId): array
    {
        $script = base_path('scripts/yandex-scraper.js');

        if (!file_exists($script)) {
            throw new YandexParseException('Файл скрапера не найден: ' . $script);
        }

        $cmd = sprintf('node %s %s', escapeshellarg($script), escapeshellarg($orgId));

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptor, $pipes);

        if (!is_resource($proc)) {
            throw new YandexParseException('Не удалось запустить Puppeteer-скрапер.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($stderr) {
            Log::warning('YandexScraper stderr', ['orgId' => $orgId, 'stderr' => substr($stderr, 0, 500)]);
        }

        if ($exitCode !== 0) {
            $errorData = json_decode($stderr, true);
            $message   = $errorData['error'] ?? $stderr;

            if (str_contains($message, 'captcha')) {
                throw new YandexParseException(
                    'Яндекс запросил капчу — запрос воспринят как бот. Попробуйте позже.'
                );
            }
            if (str_contains($message, 'Navigation failed') || str_contains($message, 'net::ERR')) {
                throw new YandexParseException(
                    'Не удалось подключиться к Яндекс.Картам: ' . $message
                );
            }
            throw new YandexParseException('Ошибка скрапера: ' . $message);
        }

        $data = json_decode($stdout, true);
        if (!is_array($data) || empty($data['org']['name'])) {
            throw new YandexParseException(
                'Не удалось распознать данные организации. ' .
                'Возможно, ссылка ведёт не на карточку организации.'
            );
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // Review normalisation
    // -------------------------------------------------------------------------

    /**
     * Приводит отзыв из вывода скрапера к единому формату.
     * Возвращает null если не хватает обязательных полей (id или date).
     */
    private function normalizeReview(array $raw): ?array
    {
        $id = $raw['id'] ?? null;
        if (!$id) {
            return null;
        }

        $author = trim((string) ($raw['author'] ?? 'Аноним')) ?: 'Аноним';
        $rating = (int) ($raw['rating'] ?? 0);

        $text = isset($raw['text']) && $raw['text'] !== ''
            ? trim(strip_tags((string) $raw['text']))
            : null;

        $dateRaw = $raw['date'] ?? null;
        if (!$dateRaw || !preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $dateRaw)) {
            return null;
        }
        $date = substr((string) $dateRaw, 0, 10);

        return [
            'id'     => (string) $id,
            'author' => $author,
            'rating' => $rating,
            'text'   => $text ?: null,
            'date'   => $date,
        ];
    }
}
