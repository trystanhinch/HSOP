<?php

use App\Http\Controllers\Api\Public\PublicIntakeController;
use App\Http\Controllers\Api\Public\PublicAvailabilityController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API (unauthenticated, rate-limited)
|--------------------------------------------------------------------------
| Used by the future public website. Keep separate from authenticated /api/*.
*/

Route::prefix('api/public')
    ->middleware(['throttle:public-intake', 'public.brand'])
    ->group(function () {
        Route::get('/brand', [PublicIntakeController::class, 'brand']);

        Route::get('/availability', [PublicAvailabilityController::class, 'index'])
            ->middleware('throttle:public-availability');
        Route::post('/availability/hold', [PublicAvailabilityController::class, 'hold'])
            ->middleware('throttle:public-availability-hold');
        Route::post('/availability/release-hold', [PublicAvailabilityController::class, 'releaseHold'])
            ->middleware('throttle:public-availability-hold');

        Route::post('/intake/start', [PublicIntakeController::class, 'start'])
            ->middleware('throttle:public-intake-start');

        Route::get('/intake/session', [PublicIntakeController::class, 'session']);

        Route::post('/intake/message', [PublicIntakeController::class, 'message'])
            ->middleware('throttle:public-intake-message');

        Route::post('/intake/media', [PublicIntakeController::class, 'media'])
            ->middleware('throttle:public-intake-media');

        Route::post('/intake/estimate', [PublicIntakeController::class, 'estimate'])
            ->middleware('throttle:public-intake-message');

        Route::post('/intake/submit', [PublicIntakeController::class, 'submit'])
            ->middleware('throttle:public-intake-submit');
    });
