<?php

namespace App\Http\Controllers;

use App\Models\ShippingAddress;
use App\Shop\ShippingAddresses;
use App\Shop\ShippingAddressFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShippingAddressController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->response(ShippingAddress::find($request->user()->id), $request->boolean('for_edit'));
    }

    public function save(Request $request, ShippingAddresses $addresses): JsonResponse
    {
        $rules = ShippingAddressFields::rules(saved: true);
        $request->merge(ShippingAddressFields::normalize($request->only(array_keys($rules)), saved: true));
        $fields = $request->validate($rules);

        return $this->response($addresses->save($request->user(), $fields));
    }

    private function response(?ShippingAddress $address, bool $forEdit = false): JsonResponse
    {
        $data = $address?->address;
        if ($data !== null) {
            $data['version'] = $address->version;
            $data['masked_phone'] = str_repeat('*', strlen($data['phone']) - 4).substr($data['phone'], -4);
            if (! $forEdit) {
                unset($data['phone']);
            }
        }

        return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
    }
}
