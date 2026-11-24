<?php

namespace App\Filament\Widgets;

use App\Dashboard\DashboardStatistics;
use App\Filament\Resources\PixPayoutResource;
use App\Filament\Resources\PixWithdrawalResource;
use App\Filament\Resources\PlatformFundOrderResource;
use App\Models\PixWithdrawal;
use App\Models\PlatformFundOrder;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OperationsQueue extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 0;

    protected ?string $heading = '待处理事项';

    protected ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return PixPayoutResource::canViewAny() || self::allowed('funds.view', 'funds.review');
    }

    protected function getStats(): array
    {
        $stats = app(DashboardStatistics::class);
        $cards = [];
        if (PixPayoutResource::canViewAny()) {
            $count = $stats->whenAvailable(fn (): int => PixPayoutResource::awaitingResult(PixPayoutResource::getEloquentQuery())->count());
            $cards[] = Stat::make('出款结果待确认', $count ?? '—')
                ->description($count === null ? '统计暂不可用' : '查看三方出款结果')
                ->url(PixPayoutResource::getUrl('index', ['filters' => ['awaiting_result' => ['isActive' => true]]]));
        }
        if (self::allowed('pix.view', 'pix.review')) {
            $count = $stats->whenAvailable(fn (): int => PixWithdrawal::query()->where('status', 'pending')->count());
            $cards[] = $this->pending('待审核提款', $count, PixWithdrawalResource::getUrl('index', $this->pendingFilter()));
        }
        if (self::allowed('funds.view', 'funds.review')) {
            $count = $stats->whenAvailable(fn (): int => PlatformFundOrder::query()->where('status', 'pending')->count());
            $cards[] = $this->pending('待审核兑换', $count, PlatformFundOrderResource::getUrl('index', $this->pendingFilter()));
        }

        return $cards;
    }

    private function pendingFilter(): array
    {
        return ['filters' => ['status' => ['value' => 'pending']]];
    }

    private function pending(string $label, ?int $count, string $url): Stat
    {
        return Stat::make($label, $count ?? '—')
            ->description($count === null ? '统计暂不可用' : '打开待审核列表')->url($url);
    }

    private static function allowed(string $view, string $review): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission($view) && $operator->hasPermission($review);
    }
}
