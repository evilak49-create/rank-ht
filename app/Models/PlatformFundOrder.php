<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'order_number', 'request_id', 'member_id', 'platform_id', 'platform_binding_id', 'fund_rate_version_id',
    'platform_account_id', 'points', 'cents_per_point', 'amount_cents', 'status',
])]
class PlatformFundOrder extends Model
{
    protected function casts(): array
    {
        return [
            'points' => 'integer', 'cents_per_point' => 'integer', 'amount_cents' => 'integer',
            'reviewed_at' => 'datetime', 'paid_at' => 'datetime',
        ];
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function task(): HasOne
    {
        return $this->hasOne(PlatformFundTask::class);
    }
}
