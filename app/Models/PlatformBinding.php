<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'member_id', 'platform_id', 'platform_account_id', 'account_name', 'query_phone',
    'baseline_deposit_cents', 'baseline_valid_bet_cents', 'baseline_observed_at', 'rescue_baseline', 'protection_status',
])]
class PlatformBinding extends Model
{
    public const PROTECTION_STATUSES = ['pending' => '待确认', 'active' => '保障中', 'inactive' => '未生效'];

    public static function protectionStatusLabel(string $status): string
    {
        return self::PROTECTION_STATUSES[$status] ?? self::PROTECTION_STATUSES['pending'];
    }

    protected function casts(): array
    {
        return [
            'baseline_observed_at' => 'datetime', 'baseline_deposit_cents' => 'integer',
            'baseline_valid_bet_cents' => 'integer', 'baseline_version' => 'integer',
            'point_remainder' => 'integer', 'rebate_remainder' => 'integer', 'settlement_paused_at' => 'datetime',
            'rescue_baseline' => 'array',
        ];
    }

    public function accountName(): string
    {
        return $this->account_name ?? $this->platform_account_id;
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function currentProtectionStatus(): string
    {
        return $this->platform->guarantee_amount_brl > 0 ? 'active' : 'inactive';
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function pointQuotes(): HasMany
    {
        return $this->hasMany(PointQuote::class);
    }
}
