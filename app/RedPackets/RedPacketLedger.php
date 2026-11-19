<?php

namespace App\RedPackets;

use App\Models\RedPacketRound;
use Illuminate\Support\Facades\DB;

class RedPacketLedger
{
    public function record(RedPacketRound $round, string $kind, int $amount, ?int $inviteId = null, ?int $withdrawalId = null, ?array $calculation = null): void
    {
        DB::table('red_packet_entries')->insert([
            'red_packet_round_id' => $round->id, 'red_packet_invite_id' => $inviteId,
            'pix_withdrawal_id' => $withdrawalId, 'kind' => $kind, 'amount_cents' => $amount,
            'balance_after' => $round->balance_cents, 'frozen_after' => $round->frozen_cents,
            'calculation' => $calculation === null ? null : json_encode($calculation, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
