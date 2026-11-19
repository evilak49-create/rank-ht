<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['model', 'api_key', 'user_id'])]
#[Hidden(['api_key'])]
class AiSetting extends Model
{
    protected function casts(): array
    {
        return ['api_key' => 'encrypted'];
    }
}
