<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'enabled', 'driver', 'configuration'])]
#[Hidden(['configuration'])]
class PixPayoutChannel extends Model
{
    protected $attributes = ['driver' => 'manual'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'configuration' => 'encrypted:array',
            'last_balance_cents' => 'integer', 'last_balance_at' => 'datetime',
            'last_balance_attempt_at' => 'datetime', 'last_balance_failed' => 'boolean',
        ];
    }
}
