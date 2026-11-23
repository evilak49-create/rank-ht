<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'member_id', 'platform_id', 'platform_binding_id', 'platform_name', 'request_id',
    'amount_cents', 'calculation', 'credited_at',
])]
class RescueFundClaim extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'calculation' => 'array', 'credited_at' => 'datetime'];
    }
}
