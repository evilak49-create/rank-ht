<?php

namespace App\RedPackets;

use App\Models\Member;
use App\Models\RedPacketInvite;
use App\Models\RedPacketRound;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvitationRewards
{
    public function __construct(private readonly RedPacketLedger $ledger) {}

    public function register(Member $invitee, string $code): void
    {
        DB::transaction(function () use ($invitee, $code): void {
            $inviter = Member::query()->where('invite_code', strtolower($code))->lockForUpdate()->first();
            if ($inviter === null || $inviter->is($invitee)) {
                throw ValidationException::withMessages(['invite_code' => '邀请链接无效。']);
            }

            $invite = RedPacketInvite::query()->firstOrCreate(['invitee_id' => $invitee->id], ['inviter_id' => $inviter->id]);
            if ((int) $invite->inviter_id !== $inviter->id || $invite->settled_at !== null) {
                return;
            }

            $round = RedPacketRound::query()->where('member_id', $inviter->id)->whereNull('closed_at')->lockForUpdate()->first();
            if ($round !== null && $round->balance_cents < $round->threshold_cents) {
                $this->settlePending($round);
            }
        }, 3);
    }

    // The caller locks the inviter and the active round in that order.
    public function settlePending(RedPacketRound $lockedRound): void
    {
        $pending = RedPacketInvite::query()->where('inviter_id', $lockedRound->member_id)
            ->whereNull('settled_at')->lockForUpdate()->lazyById(50);
        foreach ($pending as $invite) {
            $remaining = $lockedRound->threshold_cents - $lockedRound->balance_cents;
            if ($remaining <= 0) {
                break;
            }

            $bps = $remaining < 10 ? null : random_int(5000, 7000);
            $reward = $bps === null ? $remaining : intdiv($remaining * $bps, 10000);
            $lockedRound->forceFill(['balance_cents' => $lockedRound->balance_cents + $reward])->save();
            $invite->forceFill(['red_packet_round_id' => $lockedRound->id, 'reward_cents' => $reward, 'settled_at' => now()])->save();
            $this->ledger->record($lockedRound, 'invite', $reward, inviteId: $invite->id, calculation: [
                'remaining_before' => $remaining, 'method' => $bps === null ? 'tail' : 'percentage', 'bps' => $bps,
            ]);
        }
    }
}
