<?php

use App\Http\Controllers\CheckinController;
use App\Http\Controllers\MemberAuthController;
use App\Http\Controllers\PixController;
use App\Http\Controllers\PlatformController;
use App\Http\Controllers\PlatformFundsController;
use App\Http\Controllers\PointSettlementController;
use App\Http\Controllers\ProtectionController;
use App\Http\Controllers\PublicContentController;
use App\Http\Controllers\RedPacketController;
use App\Http\Controllers\ShopController;
use App\Http\Middleware\VerifyMemberSession;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function (): void {
    Route::get('health', fn () => response()->json(['status' => 'ok']));
    Route::prefix('content')->controller(PublicContentController::class)->group(function (): void {
        Route::get('banners', 'banners');
        Route::get('announcements/latest', 'latest');
        Route::get('announcements', 'announcements');
        Route::get('announcements/{article}', 'announcement')->whereNumber('article');
        Route::get('help', 'help');
        Route::get('help/{article}', 'helpArticle')->whereNumber('article');
    });
    Route::get('shop/products', [ShopController::class, 'products']);
    Route::get('shop/products/{product}', [ShopController::class, 'product'])->whereNumber('product');

    Route::get('platforms', [PlatformController::class, 'index']);
    Route::get('platforms/featured', [PlatformController::class, 'featured']);
    Route::get('platforms/{platform}', [PlatformController::class, 'show'])->whereNumber('platform');
    Route::get('platforms/{platform}/protection-terms', [ProtectionController::class, 'publicTerms'])->whereNumber('platform');
    Route::middleware(['auth:sanctum', VerifyMemberSession::class])->group(function (): void {
        Route::prefix('protection')->controller(ProtectionController::class)->group(function (): void {
            Route::get('bindings', 'bindings');
            Route::get('bindings/{binding}/terms', 'bindingTerms')->whereNumber('binding');
            Route::get('claims', 'index');
            Route::post('claims', 'store')->middleware('throttle:6,1,protection-claims');
            Route::get('claims/{claim}', 'show')->whereNumber('claim');
            Route::get('claims/{claim}/evidence/{evidence}', 'memberEvidence')->whereNumber(['claim', 'evidence'])
                ->middleware('signed:relative')->name('claim-evidence.member');
        });
        Route::get('red-packets', [RedPacketController::class, 'index']);
        Route::get('red-packets/invites', [RedPacketController::class, 'invites']);
        Route::post('red-packets/open', [RedPacketController::class, 'open'])->middleware('throttle:20,1');
        Route::get('pix-account', [PixController::class, 'account']);
        Route::put('pix-account', [PixController::class, 'saveAccount'])->middleware('throttle:10,1');
        Route::get('pix-withdrawals', [PixController::class, 'index']);
        Route::get('pix-withdrawals/{order}', [PixController::class, 'show'])->whereNumber('order');
        Route::post('pix-withdrawals', [PixController::class, 'submit'])->middleware('throttle:10,1');
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
        Route::post('platform-funds/preview', [PlatformFundsController::class, 'preview'])->middleware('throttle:20,1');
        Route::post('platform-funds/orders', [PlatformFundsController::class, 'checkout'])->middleware('throttle:20,1');
        Route::get('platform-funds/orders', [PlatformFundsController::class, 'orders']);
        Route::get('platform-funds/orders/{order}', [PlatformFundsController::class, 'order'])->whereNumber('order');
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
