<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['ai_enabled', 'welcome', 'recommended_ids', 'channels', 'user_id'])]
class SupportSetting extends Model
{
    protected function casts(): array
    {
        return ['ai_enabled' => 'boolean', 'recommended_ids' => 'array', 'channels' => 'array'];
    }
}
