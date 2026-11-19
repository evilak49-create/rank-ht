<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['cents_per_point', 'user_id'])]
class FundRateVersion extends Model
{
    protected function casts(): array
    {
        return ['cents_per_point' => 'integer'];
    }
}
