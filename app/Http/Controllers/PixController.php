<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\PixWithdrawal;
use App\Points\CashBalance;
use App\RedPackets\Cpf;
use App\RedPackets\PixWithdrawals;
use App\RedPackets\RedPackets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PixController extends Controller
{
    public function account(Request $request): JsonResponse
    {
        return $this->accountResponse($request->user());
    }

    public function saveAccount(Request $request, RedPackets $packets): JsonResponse
    {
        $request->merge(['recipient_name' => is_string($request->input('recipient_name')) ? trim($request->input('recipient_name')) : $request->input('recipient_name')]);
        $data = $request->validate([
            'cpf' => ['required', 'string', 'max:14'],
            'recipient_name' => ['required', 'string', 'max:120', 'not_regex:/[[:cntrl:]]/', 'regex:/\p{L}/u'],
        ], [
            'recipient_name.required' => '请输入 CPF 持有人的真实姓名。',
            'recipient_name.regex' => '请输入有效的真实姓名。',
            'recipient_name.not_regex' => '请输入有效的真实姓名。',
        ]);

        return $this->accountResponse($packets->savePixAccount($request->user(), $data['cpf'], $data['recipient_name']));
    }

    public function submit(Request $request, PixWithdrawals $withdrawals): JsonResponse
    {
        $data = $request->validate(['request_id' => ['required', 'uuid']]);

        return response()->json(['data' => $this->data($withdrawals->submit($request->user(), $data['request_id']))], 201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): JsonResponse
    {
        $orders = PixWithdrawal::query()->whereBelongsTo($request->user())->whereNotNull('red_packet_round_id')->latest('id')->paginate(20);

        return response()->json($orders->through($this->data(...)))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $order): JsonResponse
    {
        $withdrawal = PixWithdrawal::query()->whereBelongsTo($request->user())->whereNotNull('red_packet_round_id')->findOrFail($order);

        return response()->json(['data' => [...$this->data($withdrawal), 'cpf' => $withdrawal->cpf_snapshot, 'recipient_name' => $withdrawal->recipient_name_snapshot]])->header('Cache-Control', 'private, no-store');
    }

    public function history(Request $request): JsonResponse
    {
        $orders = PixWithdrawal::query()->whereBelongsTo($request->user())
            ->select(['id', 'order_number', 'red_packet_round_id', 'amount_cents', 'status', 'review_reason', 'rejection_deducted', 'created_at', 'paid_at'])
            ->latest('created_at')->latest('id')->paginate(20);

        return response()->json($orders->through(fn (PixWithdrawal $order): array => [
            'id' => $order->id, 'order_number' => $order->order_number, 'amount_cents' => $order->amount_cents,
            'source' => $order->red_packet_round_id === null ? 'balance' : 'red_packet',
            'status' => match ($order->status) {
                'paid' => 'succeeded',
                'failed', 'rejected' => 'failed',
                'cancelled' => 'cancelled',
                default => 'reviewing',
            },
            'lifecycle_status' => $order->status,
            'review_reason' => $order->review_reason, 'rejection_deducted' => (bool) $order->rejection_deducted,
            'created_at' => $order->created_at, 'paid_at' => $order->paid_at,
        ]))->header('Cache-Control', 'private, no-store');
    }

    public function cashBalance(Request $request): JsonResponse
    {
        $member = $request->user();

        return response()->json(['data' => [
            'available_balance_cents' => $member->available_balance_cents,
            'held_balance_cents' => $member->held_balance_cents,
            ...$this->accountData($member),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function cashWithdrawals(Request $request): JsonResponse
    {
        $orders = PixWithdrawal::query()->whereBelongsTo($request->user())->whereNull('red_packet_round_id')->latest('id')->paginate(20);

        return response()->json($orders->through($this->data(...)));
    }

    public function withdrawCash(Request $request, PixWithdrawals $withdrawals): JsonResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'amount_cents' => ['required', 'integer', 'min:1', 'max:'.CashBalance::MAX_CENTS],
        ]);
        $order = $withdrawals->submit($request->user(), $data['request_id'], (int) $data['amount_cents']);

        return response()->json(['data' => $this->data($order)], 201);
    }

    private function data(PixWithdrawal $order): array
    {
        return [
            'id' => $order->id, 'order_number' => $order->order_number, 'amount_cents' => $order->amount_cents,
            'status' => $order->status, 'currency' => 'BRL', 'masked_cpf' => Cpf::mask($order->cpf_snapshot),
            'review_reason' => $order->review_reason, 'rejection_deducted' => (bool) $order->rejection_deducted,
            'created_at' => $order->created_at, 'paid_at' => $order->paid_at,
        ];
    }

    private function accountResponse(Member $member): JsonResponse
    {
        return response()->json(['data' => $this->accountData($member)])->header('Cache-Control', 'private, no-store');
    }

    private function accountData(Member $member): array
    {
        return [
            'cpf' => $member->pix_cpf,
            'masked_cpf' => Cpf::mask($member->pix_cpf),
            'recipient_name' => $member->pix_recipient_name,
        ];
    }
}
