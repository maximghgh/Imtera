<?php

namespace App\Services\Yandex;

use RuntimeException;
use Symfony\Component\Process\Process;

class YandexPlaywrightScraper
{
    /**
     * @return array{
     *     company_name: string|null,
     *     rating: float|null,
     *     reviews_count: int|null,
     *     reviews: array<int, array{
     *         external_id: string|null,
     *         author_name: string|null,
     *         rating: float|null,
     *         body: string|null,
     *         published_at: string|null,
     *         raw_payload: array<mixed>
     *     }>
     * }|null
     */
    public function scrape(string $sourceUrl): ?array
    {
        $scriptPath = base_path('scripts/yandex-reviews/scrape.mjs');

        if (!is_file($scriptPath)) {
            return null;
        }

        $nodeBin = (string) env('YANDEX_SCRAPER_NODE_BIN', 'node');
        $maxReviews = max(100, (int) env('YANDEX_SCRAPER_MAX_REVIEWS', 5000));
        $maxScrollSteps = max(100, (int) env('YANDEX_SCRAPER_MAX_SCROLL_STEPS', 900));
        $timeoutMs = max(60000, (int) env('YANDEX_SCRAPER_TIMEOUT_MS', 180000));
        $waitMs = max(200, (int) env('YANDEX_SCRAPER_WAIT_MS', 700));
        $idleRounds = max(6, (int) env('YANDEX_SCRAPER_IDLE_ROUNDS', 28));
        $timeoutSeconds = (int) ceil($timeoutMs / 1000) + 15;
        $retries = max(1, (int) env('YANDEX_SCRAPER_RETRIES', 2));
        $browserPath = trim((string) env('YANDEX_SCRAPER_BROWSERS_PATH', (string) env('PLAYWRIGHT_BROWSERS_PATH', '')));
        if ($browserPath === '' || $browserPath === '0') {
            $browserPath = '/var/www/html/storage/ms-playwright';
        }
        $env = null;

        if ($browserPath !== '') {
            $env = array_merge($_ENV, [
                'PLAYWRIGHT_BROWSERS_PATH' => $browserPath,
            ]);
        }

        $lastCode = 'YANDEX_SCRAPER_FAILED';

        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            $process = new Process([
                $nodeBin,
                $scriptPath,
                '--url', $sourceUrl,
                '--max-reviews', (string) $maxReviews,
                '--max-scroll-steps', (string) $maxScrollSteps,
                '--timeout-ms', (string) $timeoutMs,
                '--wait-ms', (string) $waitMs,
                '--idle-rounds', (string) $idleRounds,
            ], base_path(), $env);
            $process->setTimeout($timeoutSeconds);
            $process->run();

            $output = trim($process->getOutput());
            $errorOutput = trim($process->getErrorOutput());

            if ($output === '') {
                if (!$process->isSuccessful()) {
                    $lastCode = $this->detectProcessErrorCode($errorOutput);
                    continue;
                }

                return null;
            }

            $decoded = json_decode($output, true);

            if (!is_array($decoded)) {
                $lastCode = $this->detectProcessErrorCode($errorOutput);
                continue;
            }

            if (($decoded['success'] ?? false) !== true) {
                $lastCode = (string) ($decoded['code'] ?? 'YANDEX_SCRAPER_FAILED');

                if ($lastCode === 'YANDEX_BLOCKED' && $attempt < $retries) {
                    usleep(700000 * $attempt);
                    continue;
                }

                throw new RuntimeException($lastCode);
            }

            return [
                'company_name' => is_string($decoded['company_name'] ?? null) ? $decoded['company_name'] : null,
                'rating' => is_numeric($decoded['rating'] ?? null) ? (float) $decoded['rating'] : null,
                'reviews_count' => is_numeric($decoded['reviews_count'] ?? null) ? (int) $decoded['reviews_count'] : null,
                'reviews' => $this->normalizeReviews($decoded['reviews'] ?? []),
            ];
        }

        throw new RuntimeException($lastCode);
    }

    private function detectProcessErrorCode(string $errorOutput): string
    {
        $error = mb_strtolower($errorOutput);

        if (str_contains($error, 'command not found') || str_contains($error, 'no such file or directory')) {
            return 'YANDEX_NODE_NOT_FOUND';
        }

        if (str_contains($error, 'cannot find module') && str_contains($error, 'playwright')) {
            return 'YANDEX_PLAYWRIGHT_NOT_INSTALLED';
        }

        if (str_contains($error, 'executable doesn\'t exist') || str_contains($error, 'please run the following command to download new browsers')) {
            return 'YANDEX_CHROMIUM_NOT_INSTALLED';
        }

        return 'YANDEX_SCRAPER_FAILED';
    }

    /**
     * @param mixed $reviews
     * @return array<int, array{
     *     external_id: string|null,
     *     author_name: string|null,
     *     rating: float|null,
     *     body: string|null,
     *     published_at: string|null,
     *     raw_payload: array<mixed>
     * }>
     */
    private function normalizeReviews(mixed $reviews): array
    {
        if (!is_array($reviews)) {
            return [];
        }

        $normalized = [];

        foreach ($reviews as $review) {
            if (!is_array($review)) {
                continue;
            }

            $normalized[] = [
                'external_id' => is_string($review['external_id'] ?? null) ? trim($review['external_id']) : null,
                'author_name' => is_string($review['author_name'] ?? null) ? trim($review['author_name']) : null,
                'rating' => is_numeric($review['rating'] ?? null) ? (float) $review['rating'] : null,
                'body' => is_string($review['body'] ?? null) ? trim($review['body']) : null,
                'published_at' => is_string($review['published_at'] ?? null) ? $review['published_at'] : null,
                'raw_payload' => is_array($review['raw_payload'] ?? null) ? $review['raw_payload'] : [],
            ];
        }

        return $normalized;
    }
}
