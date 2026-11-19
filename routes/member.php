<?php

use App\Http\Controllers\MemberAuthController;
use App\Http\Middleware\VerifyMemberSession;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function (): void {
    Route::get('health', fn () => response()->json(['status' => 'ok']));

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
