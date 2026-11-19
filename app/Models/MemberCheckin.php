<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'member_id', 'checkin_rule_version_id', 'business_date', 'timezone',
    'round_started_on', 'streak', 'reward_points', 'completed_round',
])]
class MemberCheckin extends Model
{
    protected function casts(): array
    {
        return [
            'streak' => 'integer',
            'reward_points' => 'integer', 'completed_round' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
