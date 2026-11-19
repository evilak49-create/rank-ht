<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ShopOrder;
use App\Shop\PhysicalShop;
use App\Shop\ShippingAddressFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    public function products(): JsonResponse
    {
        return response()->json(Product::query()->where('is_active', true)->latest('id')->paginate(20));
    }

    public function product(string $product): JsonResponse
    {
        return response()->json(['data' => Product::query()->where('is_active', true)->findOrFail($product)]);
    }

    public function checkout(Request $request, PhysicalShop $shop): JsonResponse
    {
        if (is_array($address = $request->input('address'))) {
            $request->merge(['address' => ShippingAddressFields::normalize($address)]);
        }
        $data = $request->validate([
            'product_id' => 'required|integer|min:1', 'request_id' => 'required|uuid',
            'quantity' => 'sometimes|required|integer|in:1',
            'address_version' => 'required_without:address|integer|min:1|prohibits:address',
            'address' => 'required_without:address_version|array:recipient,phone,postal_code,state,city,street,number,district,complement',
            ...($request->has('address') ? collect(ShippingAddressFields::rules())->mapWithKeys(fn ($rule, string $key) => ['address.'.$key => $rule])->all() : []),
        ]);

        return response()->json(['data' => $this->orderData($shop->checkout($request->user(), $data))], 201)->header('Cache-Control', 'private, no-store');
    }

    public function orders(Request $request): JsonResponse
    {
        $orders = ShopOrder::query()->where('member_id', $request->user()->id)->latest('id')->paginate(20);

        return response()->json($orders->through($this->orderData(...)))->header('Cache-Control', 'private, no-store');
    }

    public function order(Request $request, string $order): JsonResponse
    {
        return response()->json(['data' => $this->orderData(
            ShopOrder::query()->where('member_id', $request->user()->id)->findOrFail($order),
        )])->header('Cache-Control', 'private, no-store');
    }

    private function orderData(ShopOrder $order): array
    {
        return [
            ...$order->only([
                'id', 'order_number', 'product_id', 'product_name', 'points_price', 'address', 'status',
                'carrier', 'tracking_number', 'cancellation_reason', 'created_at', 'shipped_at', 'cancelled_at',
            ]),
            'quantity' => 1,
        ];
    }
}
