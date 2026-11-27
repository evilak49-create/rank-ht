<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'notes'])]
class PromotionChannel extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $channel): void {
            $channel->code = bin2hex(random_bytes(16));
        });
    }

    public function promotionUrl(): string
    {
        return rtrim(config('app.url'), '/').route('promotion.visit', ['channel' => $this->code], absolute: false);
    }
}
