<?php

namespace App\Activities;

use App\Models\Member;
use App\Models\MemberCheckin;
use App\Points\CashBalance;
use Illuminate\Support\Facades\DB;

class MemberCheckins
{
    public function __construct(
        private readonly BusinessClock $clock,
        private readonly CashBalance $wallet,
        private readonly CheckinRules $rules,
    ) {}

    public function record(Member $member): MemberCheckin
    {
        return DB::transaction(function () use ($member): MemberCheckin {
            $owner = Member::query()->lockForUpdate()->findOrFail($member->id);
            abort_if($owner->disabled_at !== null, 403, '会员账号已禁用。');
            abort_if($owner->session_version !== $member->session_version, 401, '会员登录会话已失效。');
            $instant = now()->utc();
            $zone = $this->clock->current($instant);
            $local = $instant->copy()->setTimezone($zone);
            $day = $local->toDateString();
            $records = MemberCheckin::query()->where('member_id', $owner->id);
            $already = (clone $records)->where('business_date', $day)->first();
            if ($already !== null) {
                return $already;
            }

            $previous = $records->where('business_date', $local->copy()->subDay()->toDateString())->first();
            [$rule, $carryRound] = $this->rules->forNextCheckin($previous);
            abort_if($rule === null || $rule->tiers === [], 409, '运营尚未配置签到奖励。');
            $days = $carryRound ? $previous->streak + 1 : 1;
            $reward = $rule->tiers[$days] ?? 0;
            $record = MemberCheckin::create([
                'member_id' => $owner->id, 'checkin_rule_version_id' => $rule->id, 'timezone' => $zone,
                'business_date' => $day, 'round_started_on' => $carryRound ? $previous->round_started_on : $day,
                'streak' => $days, 'reward_points' => 0, 'reward_cents' => $reward,
                'completed_round' => $days === max(array_keys($rule->tiers)),
            ]);

            if ($reward > 0) {
                $this->wallet->refund($owner, $reward, 'checkin', $record->id);
                $entryId = DB::table('cash_balance_entries')->where('source', 'checkin')->where('source_id', $record->id)->value('id');
                $record->forceFill(['cash_balance_entry_id' => $entryId])->save();
            }

            return $record;
        }, 3);
    }
}
