<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['threshold_cents', 'user_id'])]
class RedPacketRule extends Model
{
    protected function casts(): array
    {
        return ['threshold_cents' => 'integer'];
    }
}
