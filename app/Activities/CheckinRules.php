<?php

namespace App\Activities;

use App\Models\CheckinRuleVersion;
use App\Models\MemberCheckin;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CheckinRules
{
    public function save(array $tiers, User $operator): CheckinRuleVersion
    {
        abort_unless($operator->hasPermission('activities.manage-checkin'), 403);
        $checked = Validator::make(['tiers' => $tiers], [
            'tiers' => 'required|array|between:1,30', 'tiers.*.days' => 'required|integer|between:1,365|distinct',
            'tiers.*.reward_cents' => 'required|integer|between:1,2147483647',
        ])->validate();
        $rewards = collect($checked['tiers'])->mapWithKeys(fn (array $tier) => [(int) $tier['days'] => (int) $tier['reward_cents']])->all();
        ksort($rewards);

        return DB::transaction(function () use ($rewards, $operator): CheckinRuleVersion {
            CheckinRuleVersion::query()->oldest('id')->lockForUpdate()->firstOrFail();

            return CheckinRuleVersion::create(['tiers' => $rewards, 'user_id' => $operator->id, 'cash_rewards' => true]);
        }, 3);
    }

    public function latestCash(): ?CheckinRuleVersion
    {
        return CheckinRuleVersion::query()->where('cash_rewards', true)->latest('id')->first();
    }

    public function forNextCheckin(?MemberCheckin $previous): array
    {
        $continuing = $previous !== null && ! $previous->completed_round;
        if ($continuing) {
            $rule = CheckinRuleVersion::findOrFail($previous->checkin_rule_version_id);
            if ($rule->cash_rewards) {
                return [$rule, true];
            }
        }

        $rule = $this->latestCash();
        $continuing = $continuing && $rule !== null && $previous->streak < max(array_keys($rule->tiers));

        return [$rule, $continuing];
    }
}
