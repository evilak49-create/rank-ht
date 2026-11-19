<?php

namespace App\Activities;

use App\Models\CheckinRuleVersion;
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
            'tiers.*.points' => 'required|integer|between:1,2147483647',
        ])->validate();
        $rewards = collect($checked['tiers'])->mapWithKeys(fn (array $tier) => [(int) $tier['days'] => (int) $tier['points']])->all();
        ksort($rewards);

        return DB::transaction(function () use ($rewards, $operator): CheckinRuleVersion {
            CheckinRuleVersion::query()->oldest('id')->lockForUpdate()->firstOrFail();

            return CheckinRuleVersion::create(['tiers' => $rewards, 'user_id' => $operator->id]);
        }, 3);
    }
}
