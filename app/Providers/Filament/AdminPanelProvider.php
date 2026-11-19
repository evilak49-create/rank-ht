<?php

namespace App\Providers\Filament;

use App\Filament\Pages\AdminLogin;
use App\Filament\Resources\AnnouncementResource;
use App\Filament\Resources\BusinessTimezoneResource;
use App\Filament\Resources\HelpArticleResource;
use App\Filament\Resources\MemberCheckinResource;
use App\Filament\Resources\MemberResource;
use App\Filament\Resources\PlatformFundOrderResource;
use App\Filament\Resources\PlatformResource;
use App\Filament\Resources\PointEntryResource;
use App\Filament\Resources\PointQuoteResource;
use App\Filament\Resources\PointRuleResource;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ShopOrderResource;
use App\Filament\Resources\SiteBannerResource;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(AdminLogin::class)
            ->authGuard('web')
            ->brandName('返利网运营后台')
            ->pages([Dashboard::class])
            ->resources([
                MemberResource::class, PlatformResource::class, PointEntryResource::class,
                PointRuleResource::class, PointQuoteResource::class, ProductResource::class, ShopOrderResource::class,
                MemberCheckinResource::class, BusinessTimezoneResource::class, PlatformFundOrderResource::class,
                SiteBannerResource::class, AnnouncementResource::class, HelpArticleResource::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class], isPersistent: true);
    }
}
