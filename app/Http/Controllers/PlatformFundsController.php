<?php

namespace App\Http\Controllers;

use App\Models\PlatformFundOrder;
use App\Protection\ProtectionClaims;
use App\Shop\Funds\PlatformFunds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformFundsController extends Controller
{
    private const REQUEST = [
        'binding_id' => 'required|integer|min:1',
        'amount_cents' => 'required|integer|between:1,2147483647',
    ];

    public function rate(PlatformFunds $funds): JsonResponse
    {
        return response()->json(['data' => $funds->rate()]);
    }

    public function preview(Request $request, PlatformFunds $funds): JsonResponse
    {
        return response()->json(['data' => $funds->preview($request->user(), $request->validate(self::REQUEST))]);
    }

    public function checkout(Request $request, PlatformFunds $funds): JsonResponse
    {
        $data = $request->validate([...self::REQUEST, 'request_id' => 'required|uuid']);

        $order = $funds->checkout($request->user(), $data)->load('platform:id,name');

        return response()->json(['data' => $this->data($order)], 201);
    }

    public function orders(Request $request): JsonResponse
    {
        $orders = PlatformFundOrder::query()->where('member_id', $request->user()->id)
            ->with(['task', 'platform:id,name'])->latest('id')->paginate(20);

        return response()->json($orders->through($this->data(...)));
    }

    public function order(Request $request, string $order): JsonResponse
    {
        $record = PlatformFundOrder::query()->where('member_id', $request->user()->id)
            ->with(['task', 'platform:id,name'])->findOrFail($order);

        return response()->json(['data' => $this->data($record)]);
    }

    private function data(PlatformFundOrder $order): array
    {
        return [
            ...$order->only([
                'id', 'order_number', 'platform_id', 'points', 'cents_per_point', 'amount_cents', 'balance_paid_cents', 'status',
                'review_reason', 'reviewed_at', 'paid_at', 'created_at',
            ]),
            'platform_name' => $order->platform->name,
            'platform_account_id' => $order->platform_account_id,
            'masked_platform_account' => ProtectionClaims::maskAccount($order->platform_account_id),
            'currency' => 'BRL',
            'rate_version' => $order->fund_rate_version_id,
            'payment_refunded' => PlatformFundOrder::paymentWasRefunded($order->status, (bool) $order->rejection_deducted),
            'paused' => $order->relationLoaded('task') && $order->task?->paused_at !== null,
            'needs_review' => $order->relationLoaded('task') && $order->task?->needs_review_at !== null,
        ];
    }
}
