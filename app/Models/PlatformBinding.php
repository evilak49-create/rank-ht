<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'member_id', 'platform_id', 'platform_account_id', 'query_phone',
    'baseline_deposit_cents', 'baseline_valid_bet_cents', 'baseline_observed_at',
])]
class PlatformBinding extends Model
{
    protected function casts(): array
    {
        return [
            'baseline_observed_at' => 'datetime', 'baseline_deposit_cents' => 'integer',
            'baseline_valid_bet_cents' => 'integer', 'baseline_version' => 'integer',
            'point_remainder' => 'integer', 'settlement_paused_at' => 'datetime',
        ];
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }
}
