<?php

namespace App\Models;

use App\Platforms\PlatformNetwork;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'promotion_url', 'logo_url', 'description', 'rating', 'featured_position', 'network',
    'guarantee_amount_brl', 'max_rebate_percent',
    'minimum_deposit_brl', 'minimum_withdrawal_brl', 'withdrawal_time', 'support_url',
])]
#[Hidden(['draft_query', 'active_query'])]
class Platform extends Model
{
    public const DEFAULT_RATING = 5;

    protected $attributes = ['network' => PlatformNetwork::Panda->value];

    protected function casts(): array
    {
        return [
            'rating' => 'decimal:1',
            'guarantee_amount_brl' => 'decimal:2',
            'minimum_deposit_brl' => 'decimal:2',
            'minimum_withdrawal_brl' => 'decimal:2',
            'max_rebate_percent' => 'decimal:6',
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
