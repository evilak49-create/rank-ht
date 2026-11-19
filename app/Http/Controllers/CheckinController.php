<?php

namespace App\Http\Controllers;

use App\Activities\BusinessClock;
use App\Activities\MemberCheckins;
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
        $yesterday = $record === null
            ? $records->where('business_date', $today->copy()->subDay()->toDateString())->first()
            : null;

        return response()->json(['data' => $record === null ? [
            'checked_in' => false, 'business_date' => $today->toDateString(), 'timezone' => $zone,
            'streak' => $yesterday !== null && ! $yesterday->completed_round ? $yesterday->streak : 0,
            'reward_points' => 0,
        ] : $this->data($record)]);
    }

    public function record(Request $request, MemberCheckins $checkins): JsonResponse
    {
        return response()->json(['data' => $this->data($checkins->record($request->user()))]);
    }

    private function data(MemberCheckin $record): array
    {
        return [
            'id' => $record->id, 'checked_in' => true, 'business_date' => $record->business_date,
            'timezone' => $record->timezone, 'rule_version' => $record->checkin_rule_version_id,
            'streak' => $record->streak, 'reward_points' => $record->reward_points,
            'completed_round' => $record->completed_round, 'point_entry_id' => $record->point_entry_id,
        ];
    }
}
