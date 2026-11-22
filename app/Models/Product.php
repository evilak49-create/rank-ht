<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'description', 'image_url', 'points_price', 'price_cents', 'stock', 'is_active'])]
class Product extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'stock' => 'integer', 'points_price' => 'integer', 'price_cents' => 'integer'];
    }
}
