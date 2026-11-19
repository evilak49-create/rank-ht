<?php

namespace App\Dashboard;

use App\Activities\BusinessClock;
use Illuminate\Support\Facades\DB;

class VisitorActivity
{
    public function __construct(private readonly BusinessClock $clock) {}

    public function record(string $visitor): void
    {
        $instant = now()->utc();
        $timezone = $this->clock->current($instant);
        $date = $instant->copy()->setTimezone($timezone)->toDateString();
        $hash = hash('sha256', strtolower($visitor));

        DB::transaction(function () use ($instant, $date, $hash): void {
            DB::table('daily_visitors')->insertOrIgnore(['business_date' => $date, 'visitor_hash' => $hash]);
            DB::table('visitor_activity')->insertOrIgnore(['visitor_hash' => $hash, 'last_seen_at' => $instant]);
            DB::table('visitor_activity')->where('visitor_hash', $hash)->where('last_seen_at', '<', $instant)
                ->update(['last_seen_at' => $instant]);
        }, 3);
    }
}
