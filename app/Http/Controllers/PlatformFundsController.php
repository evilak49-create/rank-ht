<?php

namespace App\Http\Controllers;

use App\Models\PlatformFundOrder;
use App\Shop\Funds\PlatformFunds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformFundsController extends Controller
{
    private const REQUEST = ['binding_id' => 'required|integer|min:1', 'points' => 'required|integer|between:1,2147483647'];

    public function preview(Request $request, PlatformFunds $funds): JsonResponse
    {
        return response()->json(['data' => $funds->preview($request->user(), $request->validate(self::REQUEST))]);
    }

    public function checkout(Request $request, PlatformFunds $funds): JsonResponse
    {
        $data = $request->validate([...self::REQUEST, 'request_id' => 'required|uuid', 'rate_version' => 'required|integer|min:1']);

        return response()->json(['data' => $this->data($funds->checkout($request->user(), $data))], 201);
    }

    public function orders(Request $request): JsonResponse
    {
        $orders = PlatformFundOrder::query()->where('member_id', $request->user()->id)->with('task')->latest('id')->paginate(20);

        return response()->json($orders->through($this->data(...)));
    }

    public function order(Request $request, string $order): JsonResponse
    {
        $record = PlatformFundOrder::query()->where('member_id', $request->user()->id)->with('task')->findOrFail($order);

        return response()->json(['data' => $this->data($record)]);
    }

    private function data(PlatformFundOrder $order): array
    {
        return [
            ...$order->only([
                'id', 'order_number', 'platform_id', 'points', 'cents_per_point', 'amount_cents', 'status',
                'review_reason', 'reviewed_at', 'paid_at', 'created_at',
            ]),
            'currency' => 'BRL',
            'rate_version' => $order->fund_rate_version_id,
            'paused' => $order->relationLoaded('task') && $order->task?->paused_at !== null,
            'needs_review' => $order->relationLoaded('task') && $order->task?->needs_review_at !== null,
        ];
    }
}
