<?php

namespace App\Http\Controllers;

use App\Http\Requests\Yandex\StoreYandexSourceRequest;
use App\Http\Resources\ReviewResource;
use App\Http\Resources\ReviewSourceResource;
use App\Models\ReviewSource;
use Illuminate\Support\Facades\DB;
use App\Services\Yandex\YandexReviewsSyncService;
use Illuminate\Http\Request;

class YandexReviewsController extends Controller
{
    public function source(Request $request)
    {
        $source = ReviewSource::query()
            ->where('user_id', $request->user()->id)
            ->where('provider', 'yandex')
            ->first();

        return response()->json([
            'source' => $source ? new ReviewSourceResource($source) : null,
        ]);
    }

    public function storeSource(StoreYandexSourceRequest $request, YandexReviewsSyncService $syncService)
    {
        $sourceUrl = $request->validated('source_url');
        $existingSource = ReviewSource::query()
            ->where('user_id', $request->user()->id)
            ->where('provider', 'yandex')
            ->first();
        $isSourceChanged = !$existingSource || $existingSource->source_url !== $sourceUrl;

        try {
            $result = $syncService->sync($request->user(), $sourceUrl);
        } catch (\Throwable $exception) {
            $source = $existingSource;

            if ($isSourceChanged) {
                $source = DB::transaction(function () use ($request, $sourceUrl): ReviewSource {
                    $updated = ReviewSource::query()->updateOrCreate(
                        [
                            'user_id' => $request->user()->id,
                            'provider' => 'yandex',
                        ],
                        [
                            'source_url' => $sourceUrl,
                            'company_name' => null,
                            'company_rating' => null,
                            'company_reviews_count' => 0,
                            'last_synced_at' => null,
                        ],
                    );

                    $updated->reviews()->delete();

                    return $updated;
                });
            }

            return response()->json([
                'message' => $this->resolveSyncErrorMessage($exception),
                'error' => $exception->getMessage(),
                'source' => $source ? new ReviewSourceResource($source) : null,
            ], 422);
        }

        return response()->json([
            'source' => new ReviewSourceResource($result['source']),
            'reviews_synced' => $result['reviews_synced'],
        ]);
    }

    public function sync(Request $request, YandexReviewsSyncService $syncService)
    {
        $source = ReviewSource::query()
            ->where('user_id', $request->user()->id)
            ->where('provider', 'yandex')
            ->first();

        if (!$source) {
            return response()->json([
                'message' => 'Сначала сохраните ссылку на Яндекс в настройках.',
            ], 404);
        }

        try {
            $result = $syncService->sync($request->user(), $source->source_url);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => $this->resolveSyncErrorMessage($exception),
                'error' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'source' => new ReviewSourceResource($result['source']),
            'reviews_synced' => $result['reviews_synced'],
        ]);
    }

    public function reviews(Request $request)
    {
        $sort = $request->string('sort')->value();
        $perPage = max(1, min(50, (int) $request->input('per_page', 5)));

        $source = ReviewSource::query()
            ->with(['reviews' => function ($query) use ($sort) {
                if ($sort === 'oldest') {
                    $query->orderBy('published_at')->orderBy('id');
                    return;
                }

                $query->orderByDesc('published_at')->orderByDesc('id');
            }])
            ->where('user_id', $request->user()->id)
            ->where('provider', 'yandex')
            ->first();

        if (!$source) {
            return response()->json([
                'company' => null,
                'reviews' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                ],
            ]);
        }

        if ((int) ($source->company_reviews_count ?? 0) === 0) {
            return response()->json([
                'company' => new ReviewSourceResource($source),
                'reviews' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                ],
            ]);
        }

        $query = $source->reviews();

        if ($sort === 'oldest') {
            $query->orderBy('published_at')->orderBy('id');
        } else {
            $query->orderByDesc('published_at')->orderByDesc('id');
        }

        $paginated = $query->paginate($perPage);

        return response()->json([
            'company' => new ReviewSourceResource($source),
            'reviews' => ReviewResource::collection($paginated->items())->resolve(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    private function resolveSyncErrorMessage(\Throwable $exception): string
    {
        return match ($exception->getMessage()) {
            'YANDEX_BLOCKED' => 'Яндекс вернул страницу проверки "Вы не робот". Подождите и обновите еще раз.',
            'YANDEX_REVIEWS_NOT_EXTRACTED' => 'Яндекс показал карточку, но отзывы не удалось извлечь. Попробуйте обновить позже.',
            'YANDEX_NODE_NOT_FOUND' => 'Node.js не найден в окружении Laravel. Установите Node или задайте YANDEX_SCRAPER_NODE_BIN.',
            'YANDEX_PLAYWRIGHT_NOT_INSTALLED' => 'Пакет Playwright не установлен. Выполните npm install.',
            'YANDEX_CHROMIUM_NOT_INSTALLED' => 'Chromium для Playwright не установлен. Выполните npx playwright install chromium.',
            'YANDEX_SCRAPER_FAILED', 'SCRAPER_RUNTIME_ERROR' => 'Headless-парсер отзывов не запустился. Проверьте установку Playwright и Chromium.',
            default => 'Не удалось получить данные по ссылке Яндекс. Проверьте ссылку и попробуйте снова.',
        };
    }
}
