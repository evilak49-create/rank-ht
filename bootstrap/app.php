<?php

use App\Http\Controllers\SiteInstallController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Installation is restricted before Laravel sessions become available.
            Route::get('/', [SiteInstallController::class, 'home']);
            Route::get('/install/activate', [SiteInstallController::class, 'activationPage']);
            Route::post('/install/activate', [SiteInstallController::class, 'activate'])->name('site-install.activate');
            Route::get('/install', [SiteInstallController::class, 'show'])->name('site-install.show');
            Route::post('/install', [SiteInstallController::class, 'store'])->name('site-install.store');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') ? null : route('filament.admin.auth.login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
