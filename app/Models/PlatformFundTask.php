<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['next_run_at'])]
class PlatformFundTask extends Model
{
    protected function casts(): array
    {
        return [
            'next_run_at' => 'datetime', 'send_started_at' => 'datetime', 'paused_at' => 'datetime',
            'needs_review_at' => 'datetime', 'lease_until' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PlatformFundOrder::class, 'platform_fund_order_id');
    }

    public static function pauseForMember(int $memberId): void
    {
        self::pauseUnsent('member_id', $memberId, 'member_disabled');
    }

    public static function pauseForPlatform(int $platformId): void
    {
        self::pauseUnsent('platform_id', $platformId, 'platform_unavailable');
    }

    private static function pauseUnsent(string $column, int $id, string $reason): void
    {
        self::query()->whereNull('send_started_at')->whereNull('paused_at')
            ->whereHas('order', fn ($query) => $query->where($column, $id)->where('status', 'processing'))
            ->update(['paused_at' => now(), 'pause_reason' => $reason, 'next_run_at' => null]);
    }
}
