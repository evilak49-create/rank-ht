<?php

namespace App\Http\Controllers;

use App\Auth\MemberPhone;
use App\Models\Product;
use App\Models\ShopOrder;
use App\Shop\PhysicalShop;
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
            $request->merge(['address' => [...$address, 'phone' => MemberPhone::normalize($address['phone'] ?? null)]]);
        }
        $data = $request->validate([
            'product_id' => 'required|integer|min:1', 'request_id' => 'required|uuid',
            'quantity' => 'sometimes|required|integer|in:1',
            'address' => 'required|array:recipient,phone,postal_code,state,city,street,number,district,complement',
            'address.recipient' => 'required|string|max:120',
            'address.phone' => ['required', MemberPhone::RULE],
            'address.postal_code' => ['required', 'regex:/\A[0-9]{5}-?[0-9]{3}\z/'],
            'address.state' => ['required', 'regex:/\A[A-Z]{2}\z/'],
            'address.city' => 'required|string|max:120', 'address.street' => 'required|string|max:200',
            'address.number' => 'required|string|max:32', 'address.district' => 'nullable|string|max:120',
            'address.complement' => 'nullable|string|max:200',
        ]);

        return response()->json(['data' => $this->orderData($shop->checkout($request->user(), $data))], 201);
    }

    public function orders(Request $request): JsonResponse
    {
        $orders = ShopOrder::query()->where('member_id', $request->user()->id)->latest('id')->paginate(20);

        return response()->json($orders->through($this->orderData(...)));
    }

    public function order(Request $request, string $order): JsonResponse
    {
        return response()->json(['data' => $this->orderData(
            ShopOrder::query()->where('member_id', $request->user()->id)->findOrFail($order),
        )]);
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
