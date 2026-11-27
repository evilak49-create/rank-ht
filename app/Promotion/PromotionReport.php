<?php

namespace App\Promotion;

use App\Activities\BusinessClock;
use App\Models\PromotionChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PromotionReport
{
    public function __construct(private readonly BusinessClock $clock) {}

    public function today(): string
    {
        $instant = now()->utc();

        return $instant->setTimezone($this->clock->current($instant))->toDateString();
    }

    public function query(Builder $query, string $from, string $until): Builder
    {
        $timezone = $this->clock->current(now()->utc());
        $start = Carbon::parse($from, $timezone)->startOfDay()->utc();
        $end = Carbon::parse($until, $timezone)->startOfDay()->addDay()->utc();
        $visits = DB::table('promotion_visits')->selectRaw('COUNT(DISTINCT visitor_id)')
            ->whereColumn('promotion_channel_id', 'promotion_channels.id')->whereBetween('business_date', [$from, $until]);
        $registrations = DB::table('members')->selectRaw('COUNT(*)')
            ->whereColumn('promotion_channel_id', 'promotion_channels.id')
            ->where('created_at', '>=', $start)->where('created_at', '<', $end);
        $firstDeposits = DB::table('platform_recharges as recharges')
            ->join('platform_bindings as bindings', 'bindings.id', '=', 'recharges.platform_binding_id')
            ->where('recharges.amount_cents', '>', 0)
            ->whereColumn('recharges.recharged_at', '>', 'bindings.created_at')
            ->groupBy('bindings.member_id')
            ->selectRaw('bindings.member_id, MIN(recharges.recharged_at) as first_deposit_at');
        $deposits = DB::table('members')->joinSub($firstDeposits, 'deposits', 'deposits.member_id', '=', 'members.id')
            ->whereColumn('members.promotion_channel_id', 'promotion_channels.id')
            ->where('first_deposit_at', '>=', $start)->where('first_deposit_at', '<', $end)->selectRaw('COUNT(*)');
        $expenses = DB::table('promotion_expenses')->selectRaw('SUM(amount_cents)')
            ->whereColumn('promotion_channel_id', 'promotion_channels.id')->whereBetween('business_date', [$from, $until]);

        return $query->addSelect('promotion_channels.*')
            ->selectSub($visits, 'visit_count')->selectSub($registrations, 'registration_count')
            ->selectSub($deposits, 'deposit_count')->selectSub($expenses, 'expense_cents');
    }

    public function expense(PromotionChannel $channel, string $date): ?string
    {
        $cents = DB::table('promotion_expenses')->where('promotion_channel_id', $channel->id)
            ->where('business_date', $date)->value('amount_cents');

        return $cents === null ? null : sprintf('%d.%02d', intdiv((int) $cents, 100), (int) $cents % 100);
    }

    public function saveExpense(PromotionChannel $channel, string $date, string $amount): void
    {
        Validator::make(['date' => $date, 'amount' => $amount], [
            'date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'regex:/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/'],
        ])->validate();
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        DB::table('promotion_expenses')->upsert([[
            'promotion_channel_id' => $channel->id, 'business_date' => $date,
            'amount_cents' => (int) $whole * 100 + (int) str_pad($fraction, 2, '0'),
        ]], ['promotion_channel_id', 'business_date'], ['amount_cents']);
    }
}
