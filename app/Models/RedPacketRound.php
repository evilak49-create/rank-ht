<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['member_id', 'red_packet_rule_id', 'threshold_cents', 'initial_bps', 'initial_cents', 'balance_cents', 'frozen_cents'])]
class RedPacketRound extends Model
{
    protected function casts(): array
    {
        return [
            'threshold_cents' => 'integer', 'initial_bps' => 'integer', 'initial_cents' => 'integer',
            'balance_cents' => 'integer', 'frozen_cents' => 'integer', 'closed_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function invites(): HasMany
    {
        return $this->hasMany(RedPacketInvite::class);
    }
}
