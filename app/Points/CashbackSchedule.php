<?php

namespace App\Points;

use Carbon\CarbonImmutable;

class CashbackSchedule
{
    public const TIMEZONE = 'America/Sao_Paulo';

    public static function noon(): CarbonImmutable
    {
        return now(self::TIMEZONE)->toImmutable()->setTime(12, 0);
    }

    public static function information(): array
    {
        $noon = self::noon();

        return [
            'server_now' => now()->toISOString(),
            'next_dispatch_at' => ($noon->isFuture() ? $noon : $noon->addDay())->toISOString(),
        ];
    }
}
