<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'enabled'])]
class PixPayoutChannel extends Model
{
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
