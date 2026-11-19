<?php

use App\Http\Controllers\MemberAuthController;
use App\Http\Controllers\PlatformController;
use App\Http\Middleware\VerifyMemberSession;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function (): void {
    Route::get('health', fn () => response()->json(['status' => 'ok']));

    Route::get('platforms', [PlatformController::class, 'index']);
    Route::get('platforms/featured', [PlatformController::class, 'featured']);
    Route::get('platforms/{platform}', [PlatformController::class, 'show'])->whereNumber('platform');
    Route::middleware(['auth:sanctum', VerifyMemberSession::class])->group(function (): void {
        Route::get('platform-bindings', [PlatformController::class, 'bindings']);
        Route::post('platforms/{platform}/binding/code', [PlatformController::class, 'sendCode'])
            ->whereNumber('platform')->middleware('throttle:member-sms');
        Route::post('platforms/{platform}/binding', [PlatformController::class, 'bind'])
            ->whereNumber('platform')->middleware('throttle:member-verification');
    });

    Route::prefix('auth')->controller(MemberAuthController::class)->group(function (): void {
        Route::post('sms-code', 'sendCode')->middleware('throttle:member-sms');
        Route::post('register', 'register')->middleware('throttle:member-verification');
        Route::post('login', 'login')->middleware('throttle:member-login');
        Route::post('reset-password', 'resetPassword')->middleware('throttle:member-verification');

        Route::middleware(['auth:sanctum', VerifyMemberSession::class])->group(function (): void {
            Route::get('me', 'me');
            Route::post('logout', 'logout');
        });
    });
});
