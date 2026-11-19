<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['platform_id', 'user_id', 'request_id', 'quantity', 'facts', 'status'])]
class PlatformReviewBatch extends Model
{
    protected function casts(): array
    {
        return ['facts' => 'array', 'quantity' => 'integer', 'attempts' => 'integer', 'lease_until' => 'datetime', 'finished_at' => 'datetime'];
    }

    #[Scope]
    protected function due(Builder $query): void
    {
        $query->where(fn (Builder $builder) => $builder->where('status', 'pending')
            ->orWhere(fn (Builder $running) => $running->where('status', 'processing')->where('lease_until', '<=', now())));
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
