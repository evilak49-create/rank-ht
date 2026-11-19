<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['inviter_id', 'invitee_id'])]
class RedPacketInvite extends Model
{
    protected function casts(): array
    {
        return ['settled_at' => 'datetime', 'reward_cents' => 'integer'];
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'inviter_id');
    }

    public function invitee(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'invitee_id');
    }
}
