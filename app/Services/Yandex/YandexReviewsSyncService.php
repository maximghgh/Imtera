<?php

namespace App\Services\Yandex;

use App\Models\ReviewSource;
use App\Models\User;
use RuntimeException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class YandexReviewsSyncService
{
    public function __construct(
        private readonly YandexPlaywrightScraper $playwrightScraper,
    ) {
    }

    public function sync(User $user, string $sourceUrl): array
    {
        $normalizedUrl = trim($sourceUrl);
        $parsed = $this->parseWithPreferredStrategy($normalizedUrl);
        $parsed['reviews'] = $this->deduplicateParsedReviews($parsed['reviews'] ?? []);

        $reviewsCount = $parsed['reviews_count'] ?? 0;

        if ($reviewsCount > 0 && count($parsed['reviews']) > $reviewsCount) {
            $parsed['reviews'] = array_slice($parsed['reviews'], 0, $reviewsCount);
        }

        $reviewsSynced = count($parsed['reviews']);

        if ($reviewsCount === 0 && $reviewsSynced > 0) {
            $reviewsCount = $reviewsSynced;
        }

        $rating = $parsed['rating'];

        if ($rating === null && !empty($parsed['reviews'])) {
            $ratings = array_values(array_filter(array_map(static fn (array $review) => $review['rating'], $parsed['reviews']), static fn ($value) => $value !== null));
            if (!empty($ratings)) {
                $rating = round(array_sum($ratings) / count($ratings), 2);
            }
        }

        if ($reviewsCount > 0 && $reviewsSynced === 0) {
            throw new RuntimeException('YANDEX_REVIEWS_NOT_EXTRACTED');
        }

        /** @var ReviewSource $source */
        $source = DB::transaction(function () use ($user, $normalizedUrl, $parsed, $rating, $reviewsCount): ReviewSource {
            $source = ReviewSource::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'provider' => 'yandex',
                ],
                [
                    'source_url' => $normalizedUrl,
                    'company_name' => $parsed['company_name'],
                    'company_rating' => $rating,
                    'company_reviews_count' => $reviewsCount,
                    'last_synced_at' => now(),
                ],
            );

            $source->reviews()->delete();

            if (!empty($parsed['reviews'])) {
                $source->reviews()->createMany($parsed['reviews']);
            }

            return $source;
        });

        return [
            'source' => $source->fresh(),
            'reviews_synced' => $reviewsSynced,
        ];
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
    private function deduplicateParsedReviews(mixed $reviews): array
    {
        if (!is_array($reviews)) {
            return [];
        }

        $unique = [];

        foreach ($reviews as $review) {
            $normalized = $this->normalizeParsedReview($review);

            if ($normalized === null) {
                continue;
            }

            $key = $this->buildDeduplicateKey($normalized);

            if (!isset($unique[$key])) {
                $unique[$key] = $normalized;
                continue;
            }

            $unique[$key] = $this->mergeDuplicateReviews($unique[$key], $normalized);
        }

        return array_values($unique);
    }

    /**
     * @param mixed $review
     * @return array{
     *     external_id: string|null,
     *     author_name: string|null,
     *     rating: float|null,
     *     body: string|null,
     *     published_at: string|null,
     *     raw_payload: array<mixed>
     * }|null
     */
    private function normalizeParsedReview(mixed $review): ?array
    {
        if (!is_array($review)) {
            return null;
        }

        $externalId = $this->normalizeDedupText($review['external_id'] ?? null);
        $authorName = $this->normalizeDedupText($review['author_name'] ?? null);
        $body = $this->normalizeDedupBody($review['body'] ?? null);
        $publishedAt = $this->normalizeDedupDate($review['published_at'] ?? null);
        $rawPayload = is_array($review['raw_payload'] ?? null) ? $review['raw_payload'] : [];
        $rating = null;

        if (is_numeric($review['rating'] ?? null)) {
            $rating = round(min(5, max(0, (float) $review['rating'])), 2);
        }

        if ($externalId === null && $authorName === null && $body === null && $publishedAt === null && $rating === null) {
            return null;
        }

        return [
            'external_id' => $externalId,
            'author_name' => $authorName,
            'rating' => $rating,
            'body' => $body,
            'published_at' => $publishedAt,
            'raw_payload' => $rawPayload,
        ];
    }

    /**
     * @param array{
     *     external_id: string|null,
     *     author_name: string|null,
     *     rating: float|null,
     *     body: string|null,
     *     published_at: string|null,
     *     raw_payload: array<mixed>
     * } $review
     */
    private function buildDeduplicateKey(array $review): string
    {
        $author = mb_strtolower($review['author_name'] ?? '');
        $body = mb_strtolower($review['body'] ?? '');
        $publishedAt = $review['published_at'] ?? '';
        $rating = $review['rating'] !== null ? number_format((float) $review['rating'], 2, '.', '') : '';

        if ($author !== '' || $body !== '' || $publishedAt !== '' || $rating !== '') {
            $signature = implode('|', [
                $author,
                $publishedAt,
                $rating,
                mb_substr($body, 0, 220),
            ]);

            return 'semantic:' . sha1($signature);
        }

        if ($review['external_id'] !== null) {
            return 'external:' . $review['external_id'];
        }

        return 'fallback:' . sha1(json_encode($review, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: uniqid('', true));
    }

    /**
     * @param array{
     *     external_id: string|null,
     *     author_name: string|null,
     *     rating: float|null,
     *     body: string|null,
     *     published_at: string|null,
     *     raw_payload: array<mixed>
     * } $first
     * @param array{
     *     external_id: string|null,
     *     author_name: string|null,
     *     rating: float|null,
     *     body: string|null,
     *     published_at: string|null,
     *     raw_payload: array<mixed>
     * } $second
     * @return array{
     *     external_id: string|null,
     *     author_name: string|null,
     *     rating: float|null,
     *     body: string|null,
     *     published_at: string|null,
     *     raw_payload: array<mixed>
     * }
     */
    private function mergeDuplicateReviews(array $first, array $second): array
    {
        $firstScore = $this->reviewPriority($first);
        $secondScore = $this->reviewPriority($second);

        $preferred = $secondScore > $firstScore ? $second : $first;
        $fallback = $secondScore > $firstScore ? $first : $second;

        $body = $preferred['body'];
        if (($fallback['body'] !== null) && ($body === null || mb_strlen($fallback['body']) > mb_strlen($body))) {
            $body = $fallback['body'];
        }

        return [
            'external_id' => $preferred['external_id'] ?? $fallback['external_id'],
            'author_name' => $preferred['author_name'] ?? $fallback['author_name'],
            'rating' => $preferred['rating'] ?? $fallback['rating'],
            'body' => $body,
            'published_at' => $preferred['published_at'] ?? $fallback['published_at'],
            'raw_payload' => $this->isNetworkPayload($preferred['raw_payload']) ? $preferred['raw_payload'] : $fallback['raw_payload'],
        ];
    }

    /**
     * @param array{
     *     external_id: string|null,
     *     author_name: string|null,
     *     rating: float|null,
     *     body: string|null,
     *     published_at: string|null,
     *     raw_payload: array<mixed>
     * } $review
     */
    private function reviewPriority(array $review): int
    {
        $score = 0;

        if ($review['external_id'] !== null) {
            $score += 2;
        }

        if ($review['author_name'] !== null) {
            $score += 1;
        }

        if ($review['rating'] !== null) {
            $score += 1;
        }

        if ($review['published_at'] !== null) {
            $score += 1;
        }

        if ($review['body'] !== null) {
            $score += 1 + min(3, intdiv(mb_strlen($review['body']), 120));
        }

        if ($this->isNetworkPayload($review['raw_payload'])) {
            $score += 3;
        }

        return $score;
    }

    /**
     * @param array<mixed> $rawPayload
     */
    private function isNetworkPayload(array $rawPayload): bool
    {
        if (($rawPayload['source'] ?? null) === 'dom') {
            return false;
        }

        return isset($rawPayload['reviewId']) || isset($rawPayload['id']) || isset($rawPayload['author']);
    }

    private function normalizeDedupText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeDedupBody(mixed $value): ?string
    {
        $normalized = $this->normalizeDedupText($value);

        if ($normalized === null) {
            return null;
        }

        $normalized = preg_replace('/\s*(?:\.{3}|…)\s*еще$/ui', '', $normalized) ?? $normalized;
        $normalized = trim($normalized);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeDedupDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc()->format('Y-m-d H:i');
        } catch (\Throwable) {
            return $this->normalizeDedupText($value);
        }
    }

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
     * }
     */
    private function parseWithPreferredStrategy(string $sourceUrl): array
    {
        $playwrightParsed = $this->playwrightScraper->scrape($sourceUrl);

        if (is_array($playwrightParsed)) {
            return $playwrightParsed;
        }

        $html = $this->fetchHtml($sourceUrl);
        $parsed = $this->parseFromHtml($html);

        if ($this->isBlockedByCaptcha($html, $parsed['company_name'])) {
            throw new RuntimeException('YANDEX_BLOCKED');
        }

        return $parsed;
    }

    private function isBlockedByCaptcha(string $html, ?string $companyName): bool
    {
        $name = mb_strtolower((string) $companyName);

        if (str_contains($name, 'вы не робот') || str_contains($name, 'are you a robot')) {
            return true;
        }

        $haystack = mb_strtolower($html);

        return str_contains($haystack, 'вы не робот')
            || str_contains($haystack, 'подтвердите, что запросы отправляли вы')
            || str_contains($haystack, 'showcaptcha')
            || str_contains($haystack, 'smartcaptcha');
    }

    private function fetchHtml(string $sourceUrl): string
    {
        $response = Http::timeout(20)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
                'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            ])
            ->get($sourceUrl);

        $response->throw();

        return $response->body();
    }

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
     * }
     */
    private function parseFromHtml(string $html): array
    {
        $nodes = $this->extractJsonLdNodes($html);

        $companyName = null;
        $rating = null;
        $reviewsCount = null;
        $reviews = [];

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $type = Arr::wrap($node['@type'] ?? []);
            $isBusinessNode = $this->isBusinessNode($type, $node);

            if (!$isBusinessNode) {
                continue;
            }

            if ($companyName === null && is_string($node['name'] ?? null)) {
                $companyName = trim($node['name']);
            }

            $aggregate = Arr::get($node, 'aggregateRating');

            if (is_array($aggregate)) {
                if ($rating === null && is_numeric($aggregate['ratingValue'] ?? null)) {
                    $rating = (float) $aggregate['ratingValue'];
                }

                if ($reviewsCount === null && is_numeric($aggregate['reviewCount'] ?? null)) {
                    $reviewsCount = (int) $aggregate['reviewCount'];
                }
            }

            $nodeReviews = Arr::get($node, 'review');

            if (!is_array($nodeReviews)) {
                continue;
            }

            $reviewsList = array_is_list($nodeReviews) ? $nodeReviews : [$nodeReviews];

            foreach ($reviewsList as $reviewNode) {
                $parsedReview = $this->parseReviewNode($reviewNode);

                if ($parsedReview !== null) {
                    $reviews[] = $parsedReview;
                }
            }
        }

        [$fallbackRating, $fallbackReviewsCount] = $this->extractAggregateFromRawHtml($html);

        if ($rating === null) {
            $rating = $fallbackRating;
        }

        if ($reviewsCount === null) {
            $reviewsCount = $fallbackReviewsCount;
        }

        $fallbackReviews = $this->extractReviewsFromRawHtml($html);

        if (!empty($fallbackReviews)) {
            $reviews = array_merge($reviews, $fallbackReviews);
        }

        if ($companyName === null) {
            $companyName = $this->extractTitleAsCompanyName($html);
        }

        $reviews = $this->uniqueReviews($reviews);

        return [
            'company_name' => $companyName,
            'rating' => $rating,
            'reviews_count' => $reviewsCount,
            'reviews' => $reviews,
        ];
    }

    /**
     * @return array{0: float|null, 1: int|null}
     */
    private function extractAggregateFromRawHtml(string $html): array
    {
        $rating = null;
        $reviewsCount = null;

        if (preg_match('/"(?:reviewCount|reviewsCount|reviews_count|review_count)"\s*:\s*([0-9]{1,7}).{0,250}?"(?:ratingValue|averageRating|rating)"\s*:\s*"?([0-5](?:[.,][0-9])?)"?/isu', $html, $match)) {
            $reviewsCount = (int) $match[1];
            $rating = $this->normalizeRating($match[2]);
        } elseif (preg_match('/"(?:ratingValue|averageRating|rating)"\s*:\s*"?([0-5](?:[.,][0-9])?)"?[^\\n]{0,250}"(?:reviewCount|reviewsCount|reviews_count|review_count)"\s*:\s*([0-9]{1,7})/isu', $html, $match)) {
            $rating = $this->normalizeRating($match[1]);
            $reviewsCount = (int) $match[2];
        }

        if ($reviewsCount === null) {
            preg_match_all('/([0-9]{1,7})\s*отзыв(?:ов|а)?/iu', $html, $matches);
            if (!empty($matches[1])) {
                $counts = array_map(static fn (string $value) => (int) preg_replace('/\D+/', '', $value), $matches[1]);
                $counts = array_filter($counts, static fn (int $value) => $value > 0);
                if (!empty($counts)) {
                    $reviewsCount = max($counts);
                }
            }
        }

        if ($rating === null) {
            preg_match_all('/(?:рейтинг|rating)[^0-9]{0,20}([0-5](?:[.,][0-9])?)/iu', $html, $matches);
            if (!empty($matches[1])) {
                $ratings = array_values(array_filter(array_map(fn (string $value) => $this->normalizeRating($value), $matches[1]), static fn ($value) => $value !== null));
                if (!empty($ratings)) {
                    $rating = $ratings[0];
                }
            }
        }

        return [$rating, $reviewsCount];
    }

    /**
     * @return array<int, array<mixed>>
     */
    private function extractJsonLdNodes(string $html): array
    {
        preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches);

        $nodes = [];

        foreach ($matches[1] ?? [] as $rawJson) {
            $decoded = json_decode(html_entity_decode(trim($rawJson), ENT_QUOTES | ENT_HTML5), true);

            if (!is_array($decoded)) {
                continue;
            }

            foreach ($this->flattenJsonLdNodes($decoded) as $node) {
                if (is_array($node)) {
                    $nodes[] = $node;
                }
            }
        }

        return $nodes;
    }

    /**
     * @return array<int, array{
     *     external_id: string|null,
     *     author_name: string|null,
     *     rating: float|null,
     *     body: string|null,
     *     published_at: string|null,
     *     raw_payload: array<mixed>
     * }>
     */
    private function extractReviewsFromRawHtml(string $html): array
    {
        $reviews = [];

        // Fallback for pages where reviews are injected via internal JSON chunks.
        // "description" in Yandex markup often contains menu/address/metadata text,
        // so try reviewBody first and fallback to description only if needed.
        preg_match_all('/"reviewBody"\s*:\s*"((?:\\\\.|[^"\\\\])+)"/u', $html, $matches, PREG_OFFSET_CAPTURE);

        if (empty($matches[1])) {
            preg_match_all('/"description"\s*:\s*"((?:\\\\.|[^"\\\\])+)"/u', $html, $matches, PREG_OFFSET_CAPTURE);
        }

        foreach ($matches[1] ?? [] as $entry) {
            [$rawBody, $offset] = $entry;
            $body = $this->cleanText($this->decodeEscapedString($rawBody));

            if ($body === null || mb_strlen($body) < 8) {
                continue;
            }

            $windowStart = max(0, $offset - 1800);
            $window = (string) substr($html, $windowStart, 3600);

            if (!$this->hasReviewContext($window)) {
                continue;
            }

            $author = $this->extractAuthorFromWindow($window);
            $rating = $this->extractRatingFromWindow($window);
            $publishedAt = $this->extractPublishedAtFromWindow($window);
            $externalId = $this->extractExternalIdFromWindow($window);

            if ($author === null && $rating === null && $publishedAt === null) {
                continue;
            }

            if ($externalId === null) {
                $externalId = md5($body.'|'.$author.'|'.$publishedAt.'|'.$rating);
            }

            $reviews[] = [
                'external_id' => $externalId,
                'author_name' => is_string($author) ? mb_substr(trim($author), 0, 255) : null,
                'rating' => $rating,
                'body' => $body,
                'published_at' => $publishedAt,
                'raw_payload' => [
                    'source' => 'html-fallback',
                    'author' => $author,
                    'rating' => $rating,
                    'published_at' => $publishedAt,
                ],
            ];
        }

        return $this->uniqueReviews($reviews);
    }

    private function hasReviewContext(string $window): bool
    {
        return preg_match('/"(?:reviewId|review_id|businessReviewId|feedbackId|reviewRating|datePublished|author|reviewer|user)"/u', $window) === 1;
    }

    /**
     * @param array<mixed> $node
     * @return array<int, array<mixed>>
     */
    private function flattenJsonLdNodes(array $node): array
    {
        $result = [];

        if (array_is_list($node)) {
            foreach ($node as $item) {
                if (is_array($item)) {
                    $result = array_merge($result, $this->flattenJsonLdNodes($item));
                }
            }

            return $result;
        }

        if (isset($node['@graph']) && is_array($node['@graph'])) {
            foreach ($node['@graph'] as $graphNode) {
                if (is_array($graphNode)) {
                    $result = array_merge($result, $this->flattenJsonLdNodes($graphNode));
                }
            }
        }

        $result[] = $node;

        return $result;
    }

    /**
     * @param array<int, string> $types
     * @param array<mixed> $node
     */
    private function isBusinessNode(array $types, array $node): bool
    {
        $allowedTypes = [
            'LocalBusiness',
            'Organization',
            'Place',
            'Restaurant',
            'CafeOrCoffeeShop',
        ];

        foreach ($types as $type) {
            if (in_array($type, $allowedTypes, true)) {
                return true;
            }
        }

        return is_array($node['aggregateRating'] ?? null) || is_array($node['review'] ?? null);
    }

    /**
     * @param mixed $reviewNode
     * @return array{
     *     external_id: string|null,
     *     author_name: string|null,
     *     rating: float|null,
     *     body: string|null,
     *     published_at: string|null,
     *     raw_payload: array<mixed>
     * }|null
     */
    private function parseReviewNode(mixed $reviewNode): ?array
    {
        if (!is_array($reviewNode)) {
            return null;
        }

        $author = $this->cleanText($this->firstStringByPaths($reviewNode, [
            'author.name',
            'author.displayName',
            'author.fullName',
            'author.publicName',
            'author.nickname',
            'author',
            'user.name',
            'user.displayName',
            'user.fullName',
            'user.publicName',
            'user.nickname',
            'user.login',
            'authorName',
            'reviewerName',
            'reviewer',
            'userName',
            'displayName',
            'nickname',
            'login',
        ]));
        $body = $this->cleanText($this->firstStringByPaths($reviewNode, [
            'description',
            'reviewBody',
            'body',
            'text',
            'comment',
            'message',
        ]));
        $rating = $this->normalizeNumericRating($this->firstValueByPaths($reviewNode, [
            'reviewRating.ratingValue',
            'reviewRating.value',
            'ratingValue',
            'rating',
            'score',
            'stars',
            'value',
            'mark',
        ]));
        $publishedAt = $this->parseDate($this->firstStringByPaths($reviewNode, [
            'datePublished',
            'publishedAt',
            'createdAt',
            'dateCreated',
            'created',
            'date',
            'updatedAt',
        ]));
        $externalId = $this->cleanText($this->firstStringByPaths($reviewNode, [
            '@id',
            'reviewId',
            'review_id',
            'feedbackId',
            'commentId',
            'id',
            'uuid',
        ]));

        if ($body === null) {
            return null;
        }

        if ($author === null && $rating === null && $publishedAt === null && $externalId === null) {
            return null;
        }

        if (!is_string($externalId) || trim($externalId) === '') {
            $externalId = md5(implode('|', [
                (string) $author,
                (string) $body,
                (string) $publishedAt,
                (string) $rating,
            ]));
        }

        return [
            'external_id' => $externalId,
            'author_name' => is_string($author) ? mb_substr(trim($author), 0, 255) : null,
            'rating' => $rating,
            'body' => $body,
            'published_at' => $publishedAt,
            'raw_payload' => $reviewNode,
        ];
    }

    private function extractAuthorFromWindow(string $window): ?string
    {
        $patterns = [
            '/"author"\s*:\s*\{[^{}]{0,800}?"(?:name|displayName|fullName|publicName|nickname)"\s*:\s*"((?:\\\\.|[^"\\\\])+)"/u',
            '/"user"\s*:\s*\{[^{}]{0,800}?"(?:name|displayName|fullName|publicName|nickname|login)"\s*:\s*"((?:\\\\.|[^"\\\\])+)"/u',
            '/"(?:authorName|reviewerName|userName|displayName|nickname)"\s*:\s*"((?:\\\\.|[^"\\\\])+)"/u',
            '/"author"\s*:\s*"((?:\\\\.|[^"\\\\])+)"/u',
            '/itemprop=["\']author["\'][^>]*>\s*([^<]{1,200})\s*</iu',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $window, $match)) {
                continue;
            }

            $author = $this->cleanText($this->decodeEscapedString($match[1]) ?? $match[1]);

            if ($author !== null) {
                return mb_substr($author, 0, 255);
            }
        }

        return null;
    }

    private function extractRatingFromWindow(string $window): ?float
    {
        $patterns = [
            '/"reviewRating"\s*:\s*\{[^{}]{0,700}?"(?:ratingValue|value)"\s*:\s*"?([0-9](?:[.,][0-9])?)"?/u',
            '/"(?:ratingValue|stars|score|mark)"\s*:\s*"?([0-9](?:[.,][0-9])?)"?/u',
            '/itemprop=["\']ratingValue["\'][^>]*content=["\']([0-9](?:[.,][0-9])?)["\']/iu',
            '/aria-label=["\'][^"\']*([0-9](?:[.,][0-9])?)\s*(?:из|\/)\s*5[^"\']*["\']/iu',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $window, $match)) {
                continue;
            }

            $rating = $this->normalizeNumericRating($match[1]);

            if ($rating !== null) {
                return $rating;
            }
        }

        return null;
    }

    private function extractPublishedAtFromWindow(string $window): ?string
    {
        $patterns = [
            '/"(?:datePublished|publishedAt|dateCreated|createdAt|created|pubDate)"\s*:\s*"((?:\\\\.|[^"\\\\])+)"/u',
            '/datetime=["\']([^"\']+)["\']/iu',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $window, $match)) {
                continue;
            }

            $publishedAt = $this->parseDate($this->decodeEscapedString($match[1]) ?? $match[1]);

            if ($publishedAt !== null) {
                return $publishedAt;
            }
        }

        return null;
    }

    private function extractExternalIdFromWindow(string $window): ?string
    {
        $patterns = [
            '/"(?:@id|reviewId|review_id|feedbackId|commentId|businessReviewId|uuid)"\s*:\s*"((?:\\\\.|[^"\\\\])+)"/u',
            '/"(?:reviewId|review_id|feedbackId|commentId|businessReviewId)"\s*:\s*([0-9]+)/u',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $window, $match)) {
                continue;
            }

            $externalId = $this->cleanText($this->decodeEscapedString($match[1]) ?? $match[1]);

            if ($externalId !== null) {
                return $externalId;
            }
        }

        return null;
    }

    private function parseDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeRating(string $value): ?float
    {
        $normalized = str_replace(',', '.', trim($value));

        if (!is_numeric($normalized)) {
            return null;
        }

        $float = (float) $normalized;

        if ($float < 0 || $float > 5) {
            return null;
        }

        return round($float, 2);
    }

    private function normalizeNumericRating(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return $this->normalizeRating($value);
        }

        if (!is_int($value) && !is_float($value)) {
            return null;
        }

        $float = (float) $value;

        if ($float > 5 && $float <= 10) {
            $float /= 2;
        }

        if ($float < 0 || $float > 5) {
            return null;
        }

        return round($float, 2);
    }

    private function decodeEscapedString(string $value): ?string
    {
        $decoded = json_decode('"'.$value.'"', true);

        if (!is_string($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<mixed> $node
     * @param array<int, string> $paths
     */
    private function firstValueByPaths(array $node, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = Arr::get($node, $path);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $node
     * @param array<int, string> $paths
     */
    private function firstStringByPaths(array $node, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = Arr::get($node, $path);

            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            return $value;
        }

        return null;
    }

    private function cleanText(?string $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $clean = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5);
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;
        $clean = trim($clean);

        return $clean !== '' ? $clean : null;
    }

    private function extractTitleAsCompanyName(string $html): ?string
    {
        if (!preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $match)) {
            return null;
        }

        $title = trim(html_entity_decode(strip_tags($match[1])));

        if ($title === '') {
            return null;
        }

        $title = preg_replace('/\s*[-|—]\s*Яндекс.*$/ui', '', $title) ?? $title;

        return trim($title);
    }

    /**
     * @param array<int, array<mixed>> $reviews
     * @return array<int, array<mixed>>
     */
    private function uniqueReviews(array $reviews): array
    {
        $seen = [];
        $unique = [];

        foreach ($reviews as $review) {
            $key = $review['external_id'] ?? null;

            if (!is_string($key)) {
                $key = md5(json_encode($review, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: uniqid('', true));
            }

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $review;
        }

        return $unique;
    }
}
