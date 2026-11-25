<?php

namespace App\Providers;

use App\Auth\LocalLogSmsSender;
use App\Auth\MemberPhone;
use App\Auth\PersistentSessionGuard;
use App\Auth\SmsChallenges;
use App\RedPackets\PixChannel;
use App\RedPackets\SimulatedPixChannel;
use App\Shop\Funds\DisabledFundChannel;
use App\Shop\Funds\FundChannel;
use App\Shop\Funds\SimulatedFundChannel;
use Filament\Notifications\Notification;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PixChannel::class, SimulatedPixChannel::class);
        $this->app->bind(FundChannel::class, fn (): FundChannel => $this->app->environment(['local', 'testing'])
            && config('services.platform_funds.driver') === 'simulator'
            ? new SimulatedFundChannel : new DisabledFundChannel);

        $this->app->bind(SmsChallenges::class, function (): SmsChallenges {
            $canSimulate = $this->app->environment(['local', 'testing'])
                && config('services.sms.driver') === 'log';

            return new SmsChallenges($canSimulate ? new LocalLogSmsSender : null);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::extend('persistent-session', function ($app, string $name, array $config): PersistentSessionGuard {
            $guard = new PersistentSessionGuard(
                $name,
                Auth::createUserProvider($config['provider']),
                $app['session.store'],
                rehashOnLogin: config('hashing.rehash_on_login', true),
                timeboxDuration: config('auth.timebox_duration', 200000),
                hashKey: config('app.key'),
            );
            $guard->setCookieJar($app['cookie']);
            $guard->setDispatcher($app['events']);
            $guard->setRequest($app->refresh('request', $guard, 'setRequest'));

            return $guard;
        });
        Sanctum::getAccessTokenFromRequestUsing(fn (): null => null);
        Notification::configureUsing(fn (Notification $notification) => $notification->safeViews('filament.platforms.query-response'));

        RateLimiter::for('member-sms', fn (Request $request): array => [
            Limit::perMinute(10)->by('ip:'.$request->ip()),
            Limit::perMinute(1)->by('phone:'.MemberPhone::normalize($request->input('phone'))),
        ]);
        RateLimiter::for('support-conversation', fn (Request $request): array => [
            Limit::perMinute(360)->by('ip:'.$request->ip()),
            Limit::perMinute(60)->by('browser:'.hash('sha256', $request->session()->getId())),
        ]);

        foreach (['member-verification', 'member-login'] as $name) {
            RateLimiter::for($name, fn (Request $request): array => [
                Limit::perMinute(30)->by('ip:'.$request->ip()),
                Limit::perMinute(6)->by('phone:'.MemberPhone::normalize($request->input('phone'))),
            ]);
        }
    }
}
