<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['member_id', 'platform_id', 'platform_binding_id', 'platform_account_id', 'method', 'status', 'failure_reason', 'callback_at'])]
class PlatformBindingAttempt extends Model
{
    protected function casts(): array
    {
        return ['callback_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function displayStatus(): string
    {
        return $this->status === 'processing' && $this->created_at->lte(now()->subMinutes(5))
            ? 'timed_out'
            : $this->status;
    }

    public function scopeWithDisplayStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'timed_out' => $query->where('status', 'processing')->where('created_at', '<=', now()->subMinutes(5)),
            'processing' => $query->where('status', 'processing')->where('created_at', '>', now()->subMinutes(5)),
            default => $query->where('status', $status),
        };
    }

    public function succeed(PlatformBinding $binding): void
    {
        $this->update([
            'platform_binding_id' => $binding->id,
            'platform_account_id' => $binding->accountName(),
            'status' => 'succeeded',
            'failure_reason' => null,
        ]);
    }

    public function fail(string $reason): void
    {
        self::query()->whereKey($this->id)->where('status', '!=', 'succeeded')->update([
            'status' => 'failed', 'failure_reason' => mb_substr($reason, 0, 300),
        ]);
    }
}
