<?php

use App\Platform\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
| API v1 (doc 24 §2). Business resources arrive with their slices (doc 11); B0 exposes health only.
*/
Route::prefix('v1')->name('v1.')->group(function (): void {
    Route::get('health', [HealthController::class, 'live'])->name('health.live');
    Route::get('health/ready', [HealthController::class, 'ready'])->name('health.ready');
});
