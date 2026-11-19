<?php

namespace App\Shop;

use App\Models\Member;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Models\User;
use App\Points\PointWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PhysicalShop
{
    public const PERMISSIONS = ['shop.manage-products', 'shop.view-orders', 'shop.ship-orders', 'shop.cancel-orders'];

    public function __construct(private readonly PointWallet $wallet) {}

    public function checkout(Member $member, array $data): ShopOrder
    {
        return DB::transaction(function () use ($member, $data): ShopOrder {
            $owner = Member::query()->lockForUpdate()->findOrFail($member->id);
            abort_if($owner->disabled_at !== null, 403, '会员账号已禁用。');
            abort_if($owner->session_version !== $member->session_version, 401, '会员登录会话已失效。');
            $request = strtolower($data['request_id']);
            $existing = ShopOrder::query()->where('member_id', $owner->id)->where('request_id', $request)->first();
            if ($existing !== null) {
                abort_if((int) $existing->product_id !== (int) $data['product_id'] || $existing->address != $data['address'], 409, '该订单请求号已使用。');

                return $existing;
            }

            $product = Product::query()->where('is_active', true)->lockForUpdate()->findOrFail($data['product_id']);
            if ($product->stock < 1) {
                throw ValidationException::withMessages(['product_id' => '商品库存不足。']);
            }

            $order = ShopOrder::create([
                'order_number' => (string) Str::uuid(), 'request_id' => $request, 'member_id' => $owner->id,
                'product_id' => $product->id, 'product_name' => $product->name, 'points_price' => $product->points_price,
                'address' => $data['address'], 'status' => 'pending',
            ]);
            $this->wallet->record($owner, -$order->points_price, [
                'operation_key' => (string) Str::uuid(), 'reason' => '商品兑换', 'source' => 'shop_order', 'source_id' => $order->id,
            ]);
            $this->moveStock($product, -1, '商品兑换', $order);

            return $order;
        }, 3);
    }

    public function ship(ShopOrder $order, array $data, User $operator): void
    {
        abort_unless($operator->hasPermission('shop.ship-orders'), 403);
        $shipping = Validator::make($data, ['carrier' => 'required|string|max:120', 'tracking_number' => 'required|string|max:120'])->validate();

        DB::transaction(function () use ($order, $shipping, $operator): void {
            $current = ShopOrder::query()->lockForUpdate()->findOrFail($order->id);
            abort_unless($current->status === 'pending', 409, '只有待发货订单可以登记发货。');
            $current->forceFill([...$shipping, 'status' => 'shipped', 'shipped_at' => now(), 'user_id' => $operator->id])->save();
        }, 3);
    }

    public function cancel(ShopOrder $order, string $reason, User $operator): void
    {
        abort_unless($operator->hasPermission('shop.cancel-orders'), 403);
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => 'required|string|max:300'])->validate();

        DB::transaction(function () use ($order, $reason, $operator): void {
            $owner = Member::query()->lockForUpdate()->findOrFail($order->member_id);
            $product = Product::query()->lockForUpdate()->findOrFail($order->product_id);
            $current = ShopOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($current->status === 'cancelled') {
                return;
            }
            abort_unless($current->status === 'pending', 409, '只有待发货订单可以取消。');

            $this->wallet->record($owner, $current->points_price, [
                'operation_key' => (string) Str::uuid(), 'reason' => $reason, 'user_id' => $operator->id,
                'source' => 'shop_refund', 'source_id' => $current->id,
            ]);
            $this->moveStock($product, 1, $reason, $current, $operator);
            $current->forceFill([
                'status' => 'cancelled', 'cancellation_reason' => $reason, 'cancelled_at' => now(), 'user_id' => $operator->id,
            ])->save();
        }, 3);
    }

    public function saveProduct(?Product $product, array $data, User $operator): void
    {
        abort_unless($operator->hasPermission('shop.manage-products'), 403);
        $fields = Validator::make($data, [
            'name' => 'required|string|max:120', 'description' => 'nullable|string|max:2000',
            'image_url' => 'nullable|string|max:2048|url:https', 'points_price' => 'required|integer|between:1,2147483647',
            'is_active' => 'required|boolean',
        ])->validate();

        DB::transaction(function () use ($product, $fields): void {
            $record = $product === null ? new Product : Product::query()->lockForUpdate()->findOrFail($product->id);
            $record->fill($fields)->save();
        }, 3);
    }

    public function adjustStock(Product $product, array $data, User $operator): void
    {
        abort_unless($operator->hasPermission('shop.manage-products'), 403);
        $data = Validator::make($data, [
            'delta' => 'required|integer|between:-2147483647,2147483647|not_in:0',
            'reason' => 'required|string|max:300',
        ])->validate();

        DB::transaction(function () use ($product, $data, $operator): void {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $this->moveStock($locked, (int) $data['delta'], $data['reason'], operator: $operator);
        }, 3);
    }

    private function moveStock(Product $locked, int $delta, string $reason, ?ShopOrder $order = null, ?User $operator = null): void
    {
        $stock = $locked->stock + $delta;
        if ($stock < 0 || $stock > 4294967295) {
            throw ValidationException::withMessages(['delta' => '调整后的库存超出支持范围。']);
        }
        $locked->forceFill(['stock' => $stock])->save();
        DB::table('product_stock_movements')->insert([
            'product_id' => $locked->id, 'shop_order_id' => $order?->id, 'user_id' => $operator?->id,
            'delta' => $delta, 'stock_after' => $stock, 'reason' => $reason, 'created_at' => now(),
        ]);
    }
}
