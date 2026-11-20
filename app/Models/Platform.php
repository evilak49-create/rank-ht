<?php

namespace App\Models;

use App\Platforms\PlatformNetwork;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'promotion_url', 'logo_url', 'description', 'rating', 'featured_position', 'network'])]
#[Hidden(['draft_query', 'active_query'])]
class Platform extends Model
{
    public const DEFAULT_RATING = 5;

    protected $attributes = ['network' => PlatformNetwork::Panda->value];

    protected function casts(): array
    {
        return [
            'rating' => 'decimal:1',
            'network' => PlatformNetwork::class,
            'manually_disabled' => 'boolean',
            'active_query' => 'encrypted:array',
            'draft_query' => 'encrypted:array',
            'query_revision' => 'integer',
            'active_query_revision' => 'integer',
            'query_failures' => 'integer',
            'query_unhealthy_at' => 'datetime',
            'query_tested_at' => 'datetime',
        ];
    }

    #[Scope]
    protected function available(Builder $query): void
    {
        $query->where('manually_disabled', false)
            ->whereNotNull('active_query_revision')
            ->whereNull('query_unhealthy_at');
    }

    public function status(): string
    {
        return match (true) {
            $this->manually_disabled => 'disabled',
            $this->query_unhealthy_at !== null => 'unhealthy',
            $this->active_query_revision !== null => 'available',
            default => 'pending',
        };
    }

    public function bindings(): HasMany
    {
        return $this->hasMany(PlatformBinding::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PlatformReview::class);
    }

    #[Scope]
    protected function withPublicReviewCount(Builder $query): void
    {
        $query->withCount([
            'reviews as review_count' => fn (Builder $reviews) => $reviews->published(),
            'reviews as member_review_count' => fn (Builder $reviews) => $reviews->published()->where('source', 'member'),
        ]);
    }
}
