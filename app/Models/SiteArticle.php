<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['type', 'title', 'body', 'body_html', 'steps', 'target_type', 'target_id', 'sort_order', 'published', 'published_at', 'user_id'])]
class SiteArticle extends Model
{
    protected function casts(): array
    {
        return ['steps' => 'array', 'published' => 'boolean', 'published_at' => 'datetime'];
    }

    #[Scope]
    protected function public(Builder $query): void
    {
        $query->where('published', true)->where('published_at', '<=', now());
    }
}
