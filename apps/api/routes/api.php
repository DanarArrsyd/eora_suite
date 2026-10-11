<?php

use App\Platform\Auth\Http\AuthController;
use App\Platform\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
| API v1 (doc 24 §2). Business resources arrive with their slices (doc 11); B0 exposes health only.
*/
Route::prefix('v1')->name('v1.')->group(function (): void {
    Route::get('health', [HealthController::class, 'live'])->name('health.live');
    Route::get('health/ready', [HealthController::class, 'ready'])->name('health.ready');

    // Authentication (doc 25 §4, ADR-0006). Stateful SPA: GET /sanctum/csrf-cookie first, then X-XSRF-TOKEN.
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::post('tenant', [AuthController::class, 'selectTenant'])->name('tenant');
        Route::post('plant', [AuthController::class, 'selectPlant'])->name('plant');
        Route::post('invitations/accept', [AuthController::class, 'acceptInvitation'])->middleware('throttle:invitation-accept')->name('invitations.accept');
    });
});
