<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['points_per_brl_micros', 'wager_multiplier_micros', 'rebate_percent_micros', 'rebate_wager_multiplier_micros', 'rebate_tiers', 'user_id'])]
class PointRule extends Model
{
    protected function casts(): array
    {
        return [
            'points_per_brl_micros' => 'integer', 'wager_multiplier_micros' => 'integer',
            'rebate_percent_micros' => 'integer', 'rebate_wager_multiplier_micros' => 'integer',
            'rebate_tiers' => 'array',
        ];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
