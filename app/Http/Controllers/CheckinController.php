<?php

namespace App\Http\Controllers;

use App\Activities\BusinessClock;
use App\Activities\MemberCheckins;
use App\Models\CheckinRuleVersion;
use App\Models\MemberCheckin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckinController extends Controller
{
    public function status(Request $request, BusinessClock $clock): JsonResponse
    {
        $instant = now()->utc();
        $zone = $clock->current($instant);
        $today = $instant->setTimezone($zone);
        $records = MemberCheckin::query()->where('member_id', $request->user()->id);
        $record = (clone $records)->where('business_date', $today->toDateString())->first();
        if ($record !== null) {
            return response()->json(['data' => $this->data($record)]);
        }

        $yesterday = $records->where('business_date', $today->copy()->subDay()->toDateString())->first();
        $continuing = $yesterday !== null && ! $yesterday->completed_round;
        $rule = $continuing ? CheckinRuleVersion::findOrFail($yesterday->checkin_rule_version_id)
            : CheckinRuleVersion::query()->latest('id')->first();

        return response()->json(['data' => [
            'checked_in' => false, 'business_date' => $today->toDateString(), 'timezone' => $zone,
            'streak' => $continuing ? $yesterday->streak : 0,
            'reward_points' => 0,
            ...$this->ruleData($rule),
        ]]);
    }

    public function record(Request $request, MemberCheckins $checkins): JsonResponse
    {
        return response()->json(['data' => $this->data($checkins->record($request->user()))]);
    }

    private function data(MemberCheckin $record): array
    {
        return [
            'id' => $record->id, 'checked_in' => true, 'business_date' => $record->business_date,
            'timezone' => $record->timezone,
            'streak' => $record->streak, 'reward_points' => $record->reward_points,
            'completed_round' => $record->completed_round, 'point_entry_id' => $record->point_entry_id,
            ...$this->ruleData(CheckinRuleVersion::findOrFail($record->checkin_rule_version_id)),
        ];
    }

    private function ruleData(?CheckinRuleVersion $rule): array
    {
        $tiers = $rule?->tiers ?? [];
        ksort($tiers, SORT_NUMERIC);

        return [
            'rule_version' => $rule?->id,
            'tiers' => array_map(
                fn ($days, $points): array => ['days' => (int) $days, 'points' => (int) $points],
                array_keys($tiers),
                array_values($tiers),
            ),
        ];
    }
}
