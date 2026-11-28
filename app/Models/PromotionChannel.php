<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'notes', 'promotion_host'])]
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
        $origin = $this->promotion_host === null ? rtrim(config('app.url'), '/') : 'https://'.$this->promotion_host;

        return $origin.route('promotion.visit', ['channel' => $this->code], absolute: false);
    }
}
