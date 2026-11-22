<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_number', 'request_id', 'member_id', 'product_id', 'product_name', 'points_price', 'balance_paid_cents', 'address', 'address_version', 'status'])]
class ShopOrder extends Model
{
    protected function casts(): array
    {
        return ['address' => 'array', 'address_version' => 'integer', 'shipped_at' => 'datetime', 'cancelled_at' => 'datetime', 'points_price' => 'integer', 'balance_paid_cents' => 'integer'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
