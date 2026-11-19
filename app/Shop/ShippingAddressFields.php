<?php

namespace App\Shop;

use App\Auth\MemberPhone;
use Illuminate\Validation\Rule;

class ShippingAddressFields
{
    private const STATES = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA',
        'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    public static function normalize(array $address, bool $saved = false): array
    {
        $address['phone'] = MemberPhone::normalize($address['phone'] ?? null);
        if ($saved && is_string($address['state'] ?? null)) {
            $address['state'] = strtoupper($address['state']);
        }

        return $address;
    }

    public static function rules(bool $saved = false): array
    {
        return [
            'recipient' => 'required|string|max:120',
            'phone' => ['required', MemberPhone::RULE],
            'postal_code' => ['required', 'regex:/\A[0-9]{5}-?[0-9]{3}\z/'],
            'state' => ['required', $saved ? Rule::in(self::STATES) : 'regex:/\A[A-Z]{2}\z/'],
            'city' => 'required|string|max:120',
            'street' => 'required|string|max:200',
            'number' => 'required|string|max:32',
            'district' => ($saved ? 'required' : 'nullable').'|string|max:120',
            'complement' => 'nullable|string|max:200',
        ];
    }
}
