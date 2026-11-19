<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['platform_id', 'user_id', 'title', 'body'])]
class ProtectionTerm extends Model
{
    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }
}
