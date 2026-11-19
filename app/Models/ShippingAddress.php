<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['member_id', 'address', 'version'])]
#[Hidden(['address'])]
class ShippingAddress extends Model
{
    protected $primaryKey = 'member_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['address' => 'encrypted:array', 'version' => 'integer'];
    }
}
