<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['platform_id', 'member_id', 'request_id', 'display_name', 'rating', 'body', 'status'])]
class PlatformReview extends Model
{
    protected function casts(): array
    {
        return ['rating' => 'integer', 'reviewed_at' => 'datetime', 'published_at' => 'datetime'];
    }

    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', 'approved')->whereNotNull('published_at');
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
