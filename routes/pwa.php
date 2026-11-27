<?php

use App\Http\Controllers\PwaController;
use App\Http\Middleware\PreventClientApiCaching;
use Illuminate\Support\Facades\Route;

// Public installation resources run outside the session and CSRF middleware.
Route::prefix('api/v1/pwa')->middleware(PreventClientApiCaching::class)->controller(PwaController::class)->group(function (): void {
    Route::get('config', 'config');
    Route::get('manifest.webmanifest', 'manifest');
    Route::get('icons/{variant}.png', 'icon')->where('variant', '192|512|maskable|apple');
    Route::get('worker.js', 'worker');
    Route::get('offline', 'offline');
});
