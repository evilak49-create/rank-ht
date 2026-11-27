<?php

use App\Http\Controllers\ExternalPixCallbackController;
use App\Http\Controllers\SimulatedPixCallbackController;
use App\Http\Controllers\SiteImageController;
use App\Http\Controllers\SiteInstallController;
use App\Installation\InstallerAccess;
use App\Storage\PublicImages;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            require __DIR__.'/../routes/pwa.php';

            // Installation is restricted before Laravel sessions become available.
            Route::get('/', [SiteInstallController::class, 'home']);
            Route::get('/install', [SiteInstallController::class, 'show'])->name('site-install.show');
            Route::post('/install', [SiteInstallController::class, 'store'])->name('site-install.store');
            Route::post('/api/v1/pix/simulator-callback', SimulatedPixCallbackController::class);
            Route::post('/api/v1/pix/provider-callback/{channel}', ExternalPixCallbackController::class)
                ->whereNumber('channel')->middleware('throttle:240,1,pix-provider-callback')->name('pix.provider-callback');
            Route::get(PublicImages::LOCAL_PREFIX.'{filename}', SiteImageController::class)
                ->where('filename', PublicImages::LOCAL_FILENAME);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: ['db_password']);
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('api/*') ? null : route('filament.admin.auth.login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

$installedEnvironment = $app->storagePath(InstallerAccess::CONFIGURATION_FILE);
$defaultEnvironment = $app->environmentFilePath();
if (! file_exists($defaultEnvironment) && ! is_link($defaultEnvironment) && is_file($installedEnvironment)) {
    $app->useEnvironmentPath(dirname($installedEnvironment))->loadEnvironmentFrom(basename($installedEnvironment));
}

return $app;
