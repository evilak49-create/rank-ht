<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['order_number', 'request_id', 'member_id', 'red_packet_round_id', 'amount_cents', 'cpf_snapshot', 'status'])]
#[Hidden(['cpf_snapshot'])]
class PixWithdrawal extends Model
{
    protected function casts(): array
    {
        return [
            'cpf_snapshot' => 'encrypted', 'amount_cents' => 'integer',
            'reviewed_at' => 'datetime', 'paid_at' => 'datetime',
        ];
    }

    public function task(): HasOne
    {
        return $this->hasOne(PixTask::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
