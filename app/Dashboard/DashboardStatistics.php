<?php

namespace App\Dashboard;

use App\Activities\BusinessClock;
use App\Models\Member;
use App\Models\PixWithdrawal;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DashboardStatistics
{
    public function __construct(private readonly BusinessClock $clock) {}

    public function overview(bool $includeWithdrawals): array
    {
        $instant = now()->utc();
        $timezone = $this->whenAvailable(fn (): string => $this->clock->current($instant));
        if ($timezone === null) {
            return ['timezone' => null, 'members' => null, 'visitors' => null, 'withdrawals' => null];
        }
        $today = $instant->copy()->setTimezone($timezone)->startOfDay();
        $dayBefore = $today->copy()->subDay();
        $tomorrow = $today->copy()->addDay();
        $previousRange = [$dayBefore->copy()->utc(), $today->copy()->utc()];
        $currentRange = [$today->copy()->utc(), $tomorrow->copy()->utc()];

        return [
            'timezone' => $timezone,
            'members' => $this->whenAvailable(fn (): array => [
                'total' => Member::query()->count(),
                'yesterday' => $this->during(Member::query(), 'created_at', ...$previousRange)->count(),
                'today' => $this->during(Member::query(), 'created_at', ...$currentRange)->count(),
            ]),
            'visitors' => $this->whenAvailable(fn (): array => [
                'yesterday' => DB::table('daily_visitors')->where('business_date', $dayBefore->toDateString())->count(),
                'today' => DB::table('daily_visitors')->where('business_date', $today->toDateString())->count(),
                'online' => DB::table('visitor_activity')->where('last_seen_at', '>', $instant->copy()->subMinutes(5))->count(),
            ]),
            'withdrawals' => $includeWithdrawals ? $this->whenAvailable(function () use ($previousRange, $currentRange): array {
                $paid = PixWithdrawal::query()->where('status', 'paid')->whereNotNull('paid_at');

                return [
                    'total' => $this->amountAndCount(clone $paid),
                    'yesterday' => $this->amountAndCount($this->during(clone $paid, 'paid_at', ...$previousRange)),
                    'today' => $this->amountAndCount($this->during(clone $paid, 'paid_at', ...$currentRange)),
                ];
            }) : null,
        ];
    }

    private function during(Builder $query, string $column, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query->where($column, '>=', $start)->where($column, '<', $end);
    }

    private function amountAndCount(Builder $query): array
    {
        $value = $query->toBase()->selectRaw('COALESCE(SUM(amount_cents), 0) AS amount, COUNT(*) AS count')->first();

        return ['amount' => (int) $value->amount, 'count' => (int) $value->count];
    }

    public function whenAvailable(Closure $read): mixed
    {
        try {
            return $read();
        } catch (QueryException $exception) {
            report($exception);

            return null;
        }
    }
}
