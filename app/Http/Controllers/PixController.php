<?php

namespace App\Http\Controllers;

use App\Models\PixWithdrawal;
use App\RedPackets\Cpf;
use App\RedPackets\PixWithdrawals;
use App\RedPackets\RedPackets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PixController extends Controller
{
    public function account(Request $request): JsonResponse
    {
        return $this->maskedAccount($request->user()->pix_cpf);
    }

    public function saveAccount(Request $request, RedPackets $packets): JsonResponse
    {
        $data = $request->validate(['cpf' => ['required', 'string', 'max:14']]);

        return $this->maskedAccount($packets->saveCpf($request->user(), $data['cpf']));
    }

    public function submit(Request $request, PixWithdrawals $withdrawals): JsonResponse
    {
        $data = $request->validate(['request_id' => ['required', 'uuid']]);

        return response()->json(['data' => $this->data($withdrawals->submit($request->user(), $data['request_id']))], 201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): JsonResponse
    {
        $orders = PixWithdrawal::query()->whereBelongsTo($request->user())->latest('id')->paginate(20);

        return response()->json($orders->through($this->data(...)))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $order): JsonResponse
    {
        $withdrawal = PixWithdrawal::query()->whereBelongsTo($request->user())->findOrFail($order);

        return response()->json(['data' => $this->data($withdrawal)])->header('Cache-Control', 'private, no-store');
    }

    private function data(PixWithdrawal $order): array
    {
        return [
            'id' => $order->id, 'order_number' => $order->order_number, 'amount_cents' => $order->amount_cents,
            'status' => $order->status, 'currency' => 'BRL', 'masked_cpf' => Cpf::mask($order->cpf_snapshot),
            'review_reason' => $order->review_reason, 'created_at' => $order->created_at, 'paid_at' => $order->paid_at,
        ];
    }

    private function maskedAccount(?string $cpf): JsonResponse
    {
        return response()->json(['data' => ['masked_cpf' => Cpf::mask($cpf)]])->header('Cache-Control', 'private, no-store');
    }
}
