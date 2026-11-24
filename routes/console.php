<?php

use App\Jobs\DispatchDailyCashback;
use App\Models\PlatformBinding;
use App\Points\CashbackSchedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('funds:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('pix:dispatch')->everyMinute()->withoutOverlapping();
Schedule::command('platform-reviews:dispatch')->everyMinute()->withoutOverlapping();

Artisan::command('cashback:dispatch', function (): void {
    $noon = CashbackSchedule::noon();
    if ($noon->isFuture()) {
        return;
    }
    $day = $noon->toDateString();
    PlatformBinding::query()
        ->whereHas('platform', fn (Builder $platform) => $platform->available())
        ->whereHas('member', fn (Builder $member) => $member->whereNull('disabled_at'))
        ->whereDoesntHave('pointQuotes', fn (Builder $quotes) => $quotes->where('dispatch_day', $day))
        ->select('id')->chunkById(200, function ($bindings) use ($day): void {
            foreach ($bindings as $binding) {
                DispatchDailyCashback::dispatch($binding->id, $day);
            }
        });
})->purpose('Queue the daily cash rebate settlements');

Schedule::command('cashback:dispatch')->dailyAt('12:00')->timezone(CashbackSchedule::TIMEZONE)->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
