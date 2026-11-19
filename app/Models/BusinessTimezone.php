<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['timezone', 'effective_at', 'user_id'])]
class BusinessTimezone extends Model
{
    protected function casts(): array
    {
        return ['effective_at' => 'datetime'];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
