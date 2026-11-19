<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['points_per_brl_micros', 'wager_multiplier_micros', 'user_id'])]
class PointRule extends Model
{
    protected function casts(): array
    {
        return ['points_per_brl_micros' => 'integer', 'wager_multiplier_micros' => 'integer'];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
