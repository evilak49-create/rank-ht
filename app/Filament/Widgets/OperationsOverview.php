<?php

namespace App\Filament\Widgets;

use App\Dashboard\DashboardStatistics;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\Computed;

class OperationsOverview extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected ?string $heading = '数据概览';

    protected ?string $pollingInterval = '60s';

    #[Computed]
    public function snapshot(): array
    {
        return app(DashboardStatistics::class)->overview($this->canViewWithdrawals());
    }

    protected function getDescription(): string
    {
        $timezone = $this->snapshot['timezone'];

        return $timezone === null ? '业务时区暂不可用' : '业务时区 '.$timezone.' · 在线人数按最近 5 分钟的前台活动计算';
    }

    protected function getStats(): array
    {
        $snapshot = $this->snapshot;
        $stats = [
            $this->metric('总注册', $snapshot['members']['total'] ?? null),
            $this->metric('昨日注册', $snapshot['members']['yesterday'] ?? null),
            $this->metric('今日注册', $snapshot['members']['today'] ?? null),
            $this->metric('昨日活跃访客', $snapshot['visitors']['yesterday'] ?? null),
            $this->metric('今日活跃访客', $snapshot['visitors']['today'] ?? null),
            $this->metric('当前在线', $snapshot['visitors']['online'] ?? null),
        ];
        if ($this->canViewWithdrawals()) {
            foreach (['total' => '总提现', 'yesterday' => '昨日提现', 'today' => '今日提现'] as $period => $label) {
                $paid = $snapshot['withdrawals'][$period] ?? null;
                $stats[] = $paid === null
                    ? $this->metric($label, null)
                    : Stat::make($label, $this->brl($paid['amount']))->description($paid['count'].' 笔已到账');
            }
        }

        return $stats;
    }

    private function metric(string $label, ?int $value): Stat
    {
        return $value === null ? Stat::make($label, '—')->description('统计暂不可用') : Stat::make($label, $value);
    }

    private function brl(int $cents): string
    {
        return 'R$ '.number_format(intdiv($cents, 100), 0, ',', '.').','.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function canViewWithdrawals(): bool
    {
        $operator = Filament::auth()->user();

        return $operator instanceof User && $operator->hasPermission('pix.view');
    }
}
