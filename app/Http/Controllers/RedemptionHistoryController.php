<?php

namespace App\Http\Controllers;

use App\Activities\RecordDateRange;
use App\Models\PlatformFundOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

class RedemptionHistoryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate(['type' => 'sometimes|required|in:all,goods,funds', 'page' => 'sometimes|required|integer|min:1']);
        $dates = RecordDateRange::fromRequest($request);
        $member = $request->user();

        $goods = DB::table('shop_orders')->where('member_id', $member->id)
            ->select(['id', 'order_number', 'product_name as name', 'points_price as points', 'balance_paid_cents', 'status', 'created_at'])
            ->selectRaw('? as type, null as rejection_deducted', ['goods']);
        $funds = DB::table('platform_fund_orders as orders')->join('platforms', 'platforms.id', '=', 'orders.platform_id')
            ->where('orders.member_id', $member->id)
            ->select(['orders.id', 'orders.order_number', 'platforms.name', 'orders.points', 'orders.balance_paid_cents', 'orders.status', 'orders.created_at'])
            ->selectRaw('? as type, orders.rejection_deducted as rejection_deducted', ['funds']);

        $dates->apply($goods, 'shop_orders.created_at');
        $dates->apply($funds, 'orders.created_at');

        $orders = match ($filters['type'] ?? 'all') {
            'goods' => $goods,
            'funds' => $funds,
            default => $goods->unionAll($funds),
        };

        $page = DB::query()->fromSub($orders, 'redemptions')
            ->orderByDesc('created_at')->orderBy('type')->orderByDesc('id')
            ->paginate(20)->appends($request->only('type') + $dates->filters);

        return response()->json($page->through(fn (stdClass $order): array => [
            'id' => (int) $order->id,
            'type' => $order->type,
            'order_number' => $order->order_number,
            'name' => $order->name,
            'points' => (int) $order->points,
            'balance_paid_cents' => $order->balance_paid_cents === null ? null : (int) $order->balance_paid_cents,
            'status' => $order->status,
            ...($order->type === 'funds' ? [
                'payment_refunded' => PlatformFundOrder::paymentWasRefunded($order->status, (bool) $order->rejection_deducted),
            ] : []),
            'created_at' => $order->created_at === null ? null : Carbon::parse($order->created_at)->toISOString(),
        ]));
    }
}
