<?php

namespace App\Http\Controllers;

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
        $member = $request->user();

        $goods = DB::table('shop_orders')->where('member_id', $member->id)
            ->select(['id', 'order_number', 'product_name as name', 'points_price as points', 'status', 'created_at'])
            ->selectRaw('? as type', ['goods']);
        $funds = DB::table('platform_fund_orders as orders')->join('platforms', 'platforms.id', '=', 'orders.platform_id')
            ->where('orders.member_id', $member->id)
            ->select(['orders.id', 'orders.order_number', 'platforms.name', 'orders.points', 'orders.status', 'orders.created_at'])
            ->selectRaw('? as type', ['funds']);

        $orders = match ($filters['type'] ?? 'all') {
            'goods' => $goods,
            'funds' => $funds,
            default => $goods->unionAll($funds),
        };

        $page = DB::query()->fromSub($orders, 'redemptions')
            ->orderByDesc('created_at')->orderBy('type')->orderByDesc('id')
            ->paginate(20)->appends($request->only('type'));

        return response()->json($page->through(fn (stdClass $order): array => [
            'id' => (int) $order->id,
            'type' => $order->type,
            'order_number' => $order->order_number,
            'name' => $order->name,
            'points' => (int) $order->points,
            'status' => $order->status,
            'created_at' => $order->created_at === null ? null : Carbon::parse($order->created_at)->toISOString(),
        ]));
    }
}
