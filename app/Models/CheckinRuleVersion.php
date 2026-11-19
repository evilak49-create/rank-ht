<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tiers', 'user_id'])]
class CheckinRuleVersion extends Model
{
    protected function casts(): array
    {
        return ['tiers' => 'array'];
    }
}
