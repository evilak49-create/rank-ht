<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['member_id', 'platform_id', 'platform_binding_id', 'point_rule_id', 'baseline_version', 'estimated_points', 'rebate_cents', 'eligibility', 'snapshot', 'expires_at', 'dispatch_day'])]
class PointQuote extends Model
{
    protected function casts(): array
    {
        return [
            'baseline_version' => 'integer', 'estimated_points' => 'integer', 'rebate_cents' => 'integer', 'snapshot' => 'array',
            'expires_at' => 'datetime', 'confirmed_at' => 'datetime',
        ];
    }

    public function status(): string
    {
        return match (true) {
            $this->confirmed_at !== null => 'confirmed',
            $this->expires_at->lte(now()) => 'expired',
            default => 'pending',
        };
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }
}
