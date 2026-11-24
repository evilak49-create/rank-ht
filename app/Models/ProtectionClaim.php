<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'request_id', 'member_id', 'platform_binding_id', 'platform_id', 'protection_term_id',
    'platform_name', 'platform_account_id', 'protection_status', 'amount_cents', 'withdrawn_at', 'description',
])]
class ProtectionClaim extends Model
{
    public const CASE_STATUSES = ['pending' => '待处理', 'resolved' => '已解决', 'unresolved' => '未解决'];

    protected $attributes = ['case_status' => 'pending'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'withdrawn_at' => 'datetime'];
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(ClaimEvidence::class)->orderBy('position');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
