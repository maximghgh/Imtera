<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\YandexReviewsController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::prefix('auth')->group(function () {
    Route::post('/login-or-register', [AuthController::class, 'loginOrRegister']);
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::get('/yandex/source', [YandexReviewsController::class, 'source']);
        Route::post('/yandex/source', [YandexReviewsController::class, 'storeSource']);
        Route::post('/yandex/source/sync', [YandexReviewsController::class, 'sync']);
        Route::get('/yandex/reviews', [YandexReviewsController::class, 'reviews']);
    });
});
