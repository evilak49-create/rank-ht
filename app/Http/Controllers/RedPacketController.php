<?php

namespace App\Http\Controllers;

use App\Models\RedPacketInvite;
use App\Models\RedPacketRound;
use App\RedPackets\RedPackets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RedPacketController extends Controller
{
    public function index(Request $request, RedPackets $packets): JsonResponse
    {
        $member = $request->user();
        $code = $packets->inviteCode($member);
        $round = RedPacketRound::query()->whereBelongsTo($member)->whereNull('closed_at')->first();

        return response()->json(['data' => [
            'invite_code' => $code, 'invite_url' => '/?invite_code='.$code.'#register/invite',
            'round' => $round === null ? null : $this->round($round),
            'page_copy' => $packets->pageCopy(),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function open(Request $request, RedPackets $packets): JsonResponse
    {
        $data = $request->validate(['request_id' => ['required', 'uuid']]);

        return response()->json(['data' => $this->round($packets->open($request->user(), $data['request_id']))]);
    }

    public function invites(Request $request): JsonResponse
    {
        $list = RedPacketInvite::query()->where('inviter_id', $request->user()->id)->latest('id')->paginate(20);

        return response()->json($list->through(fn (RedPacketInvite $invite): array => [
            'id' => $invite->id, 'round_id' => $invite->red_packet_round_id,
            'reward_cents' => $invite->reward_cents, 'settled_at' => $invite->settled_at,
            'registered_at' => $invite->created_at,
        ]));
    }

    private function round(RedPacketRound $round): array
    {
        return [
            'id' => $round->id, 'currency' => 'BRL', 'threshold_cents' => $round->threshold_cents,
            'initial_cents' => $round->initial_cents, 'balance_cents' => $round->balance_cents,
            'frozen_cents' => $round->frozen_cents, 'available_cents' => $round->balance_cents - $round->frozen_cents,
            'remaining_cents' => $round->closed_at === null ? $round->threshold_cents - $round->balance_cents : 0,
            'closed_at' => $round->closed_at,
        ];
    }
}
