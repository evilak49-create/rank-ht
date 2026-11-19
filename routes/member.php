<?php

use App\Http\Controllers\CheckinController;
use App\Http\Controllers\MemberAuthController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PointSettlementController;
use App\Http\Controllers\ShopController;
use App\Http\Middleware\VerifyMemberSession;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function (): void {
    Route::get('health', fn () => response()->json(['status' => 'ok']));
    Route::get('shop/products', [ShopController::class, 'products']);
    Route::get('shop/products/{product}', [ShopController::class, 'product'])->whereNumber('product');

    Route::get('platforms', [PlatformController::class, 'index']);
    Route::get('platforms/featured', [PlatformController::class, 'featured']);
    Route::get('platforms/{platform}', [PlatformController::class, 'show'])->whereNumber('platform');
    Route::middleware(['auth:sanctum', VerifyMemberSession::class])->group(function (): void {
        Route::get('check-in', [CheckinController::class, 'status']);
        Route::post('check-in', [CheckinController::class, 'record']);
        Route::get('platform-bindings', [PlatformController::class, 'bindings']);
        Route::post('platform-bindings/{binding}/point-quotes', [PointSettlementController::class, 'quote'])
            ->whereNumber('binding')->middleware('throttle:10,1');
        Route::post('point-quotes/{quote}/confirm', [PointSettlementController::class, 'confirm'])
            ->whereNumber('quote');
        Route::get('shop/orders', [ShopController::class, 'orders']);
        Route::get('shop/orders/{order}', [ShopController::class, 'order'])->whereNumber('order');
        Route::post('shop/orders', [ShopController::class, 'checkout'])->middleware('throttle:20,1');
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
