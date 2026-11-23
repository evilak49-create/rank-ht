<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashBalanceEntry extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'balance_after_cents' => 'integer'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
