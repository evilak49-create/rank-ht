<?php

namespace App\Activities;

use App\Models\CheckinRuleVersion;
use App\Models\Member;
use App\Models\MemberCheckin;
use App\Points\PointWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MemberCheckins
{
    public function __construct(private readonly BusinessClock $clock, private readonly PointWallet $wallet) {}

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
            $carryRound = $previous !== null && ! $previous->completed_round;
            $rule = $carryRound ? CheckinRuleVersion::findOrFail($previous->checkin_rule_version_id)
                : CheckinRuleVersion::query()->latest('id')->firstOrFail();
            abort_if($rule->tiers === [], 409, '运营尚未配置签到奖励。');
            $days = $carryRound ? $previous->streak + 1 : 1;
            $reward = $rule->tiers[$days] ?? 0;
            $record = MemberCheckin::create([
                'member_id' => $owner->id, 'checkin_rule_version_id' => $rule->id, 'timezone' => $zone,
                'business_date' => $day, 'round_started_on' => $carryRound ? $previous->round_started_on : $day,
                'streak' => $days, 'reward_points' => $reward,
                'completed_round' => $days === max(array_keys($rule->tiers)),
            ]);

            if ($reward > 0) {
                $entry = $this->wallet->record($owner, $reward, [
                    'operation_key' => (string) Str::uuid(), 'reason' => '连续签到奖励',
                    'source' => 'checkin', 'source_id' => $record->id,
                ]);
                $record->forceFill(['point_entry_id' => $entry->id])->save();
            }

            return $record;
        }, 3);
    }
}
