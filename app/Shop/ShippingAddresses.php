<?php

namespace App\Shop;

use App\Models\Member;
use App\Models\ShippingAddress;
use Illuminate\Support\Facades\DB;

class ShippingAddresses
{
    public function save(Member $member, array $fields): ShippingAddress
    {
        $fields['postal_code'] = str_replace('-', '', $fields['postal_code']);
        $fields['complement'] ??= null;

        return DB::transaction(function () use ($member, $fields): ShippingAddress {
            // Checkout uses the same member lock before checking the confirmed version.
            $owner = Member::query()->lockForUpdate()->findOrFail($member->id);
            abort_if($owner->disabled_at !== null, 403, '会员账号已禁用。');
            abort_if($owner->session_version !== $member->session_version, 401, '会员登录会话已失效。');

            $address = ShippingAddress::find($owner->id) ?? new ShippingAddress(['member_id' => $owner->id]);
            if ($address->address != $fields) {
                $address->fill(['address' => $fields, 'version' => ($address->version ?? 0) + 1])->save();
            }

            return $address;
        }, 3);
    }
}
