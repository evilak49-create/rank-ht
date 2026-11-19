<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['question', 'aliases', 'answer', 'published', 'help_article_id', 'user_id'])]
class SupportFaq extends Model
{
    protected function casts(): array
    {
        return ['aliases' => 'array', 'published' => 'boolean'];
    }
}
