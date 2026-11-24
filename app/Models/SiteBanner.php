<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'image_url', 'target_type', 'target_id', 'target_path', 'sort_order', 'active', 'user_id'])]
class SiteBanner extends Model
{
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
