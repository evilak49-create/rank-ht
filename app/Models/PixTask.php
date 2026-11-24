<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['next_run_at'])]
class PixTask extends Model
{
    protected function casts(): array
    {
        return [
            'next_run_at' => 'datetime', 'send_started_at' => 'datetime', 'paused_at' => 'datetime',
            'needs_review_at' => 'datetime', 'lease_until' => 'datetime',
        ];
    }

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(PixWithdrawal::class, 'pix_withdrawal_id');
    }

    public static function pauseForMember(int $memberId): void
    {
        self::query()->whereNull('send_started_at')->whereNull('paused_at')
            ->whereHas('withdrawal', fn ($query) => $query->where('member_id', $memberId)->where('status', 'processing'))
            ->update(['paused_at' => now(), 'next_run_at' => null]);
    }

    public static function resumeProviderPayoutsForMember(int $memberId): void
    {
        self::query()->whereNull('send_started_at')->whereNotNull('paused_at')
            ->whereHas('withdrawal', fn ($query) => $query->where('member_id', $memberId)->where('status', 'processing')
                ->whereNotNull('payout_number'))
            ->update(['paused_at' => null, 'next_run_at' => now()]);
    }
}
