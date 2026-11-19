<?php

namespace App\RedPackets;

use App\Models\Member;
use App\Models\RedPacketRound;
use App\Models\RedPacketRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RedPackets
{
    public const PERMISSIONS = ['red-packets.manage', 'red-packets.view', 'pix.view', 'pix.review', 'pix.resume'];

    public function __construct(private readonly InvitationRewards $invitations, private readonly RedPacketLedger $ledger) {}

    public function open(Member $member, string $request): RedPacketRound
    {
        return DB::transaction(function () use ($member, $request): RedPacketRound {
            $owner = $this->lockActive($member);
            $request = strtolower($request);
            $reference = DB::table('red_packet_open_requests')
                ->where(['member_id' => $owner->id, 'request_id' => $request])->value('red_packet_round_id');
            if ($reference !== null) {
                return RedPacketRound::findOrFail($reference);
            }

            $round = RedPacketRound::query()->where('member_id', $owner->id)->whereNull('closed_at')->lockForUpdate()->first();
            if ($round === null) {
                $rules = RedPacketRule::query()->latest('id')->firstOrFail();
                $bps = random_int(8500, 9500);
                $initial = intdiv($rules->threshold_cents * $bps, 10000);
                $round = RedPacketRound::create([
                    'member_id' => $owner->id, 'red_packet_rule_id' => $rules->id, 'threshold_cents' => $rules->threshold_cents,
                    'initial_bps' => $bps, 'initial_cents' => $initial, 'balance_cents' => $initial, 'frozen_cents' => 0,
                ]);
                $this->ledger->record($round, 'initial', $initial, calculation: ['bps' => $bps, 'threshold_cents' => $rules->threshold_cents]);
                $this->invitations->settlePending($round);
            }
            DB::table('red_packet_open_requests')->insert([
                'member_id' => $owner->id, 'request_id' => $request, 'red_packet_round_id' => $round->id,
            ]);

            return $round;
        }, 3);
    }

    public function inviteCode(Member $member): string
    {
        if ($member->invite_code !== null) {
            return $member->invite_code;
        }

        return DB::transaction(function () use ($member): string {
            $owner = $this->lockActive($member);
            if ($owner->invite_code === null) {
                $owner->forceFill(['invite_code' => bin2hex(random_bytes(16))])->save();
            }

            return $owner->invite_code;
        }, 3);
    }

    public function saveCpf(Member $member, string $value): string
    {
        $cpf = Cpf::normalize($value);
        DB::transaction(fn () => $this->lockActive($member)->forceFill(['pix_cpf' => $cpf])->save(), 3);

        return $cpf;
    }

    public function saveThreshold(string $value, User $operator): RedPacketRule
    {
        abort_unless($operator->hasPermission('red-packets.manage'), 403);
        if (! preg_match('/\A([0-9]{1,6})(?:\.([0-9]{1,2}))?\z/', $value, $matches)) {
            throw ValidationException::withMessages(['threshold' => '请输入最多两位小数的 BRL 金额。']);
        }
        $cents = (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($cents < 1) {
            throw ValidationException::withMessages(['threshold' => '提现门槛必须大于零。']);
        }

        return DB::transaction(function () use ($cents, $operator): RedPacketRule {
            RedPacketRule::query()->oldest('id')->lockForUpdate()->firstOrFail();

            return RedPacketRule::create(['threshold_cents' => $cents, 'user_id' => $operator->id]);
        }, 3);
    }

    public function lockActive(Member $member): Member
    {
        $owner = Member::query()->lockForUpdate()->findOrFail($member->id);
        abort_if($owner->disabled_at !== null, 403, '会员账号已禁用。');
        abort_if($owner->session_version !== $member->session_version, 401, '会员登录会话已失效。');

        return $owner;
    }
}
