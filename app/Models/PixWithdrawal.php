<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['order_number', 'request_id', 'member_id', 'red_packet_round_id', 'amount_cents', 'cpf_snapshot', 'status'])]
#[Hidden(['cpf_snapshot', 'payout_configuration'])]
class PixWithdrawal extends Model
{
    protected function casts(): array
    {
        return [
            'cpf_snapshot' => 'encrypted', 'amount_cents' => 'integer',
            'reviewed_at' => 'datetime', 'paid_at' => 'datetime', 'payout_channel_id' => 'integer',
            'rejection_deducted' => 'boolean', 'payout_configuration' => 'encrypted:array',
            'callback_received_at' => 'datetime',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function usesManualPayout(?PixTask $task): bool
    {
        return $task === null && ($this->payout_channel_id !== null || $this->red_packet_round_id === null);
    }

    public function canCloseWithoutPayout(?PixTask $task): bool
    {
        return $this->status === 'pending' || ($this->status === 'processing' && $this->usesManualPayout($task));
    }
}
