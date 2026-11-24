<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['platform_id', 'user_id', 'title', 'body'])]
class ProtectionTerm extends Model
{
    public const PLACEHOLDERS = ['【平台名称】', '【保障金额】', '【会员账号】'];

    public static function currentForPlatform(int $platformId): ?self
    {
        return static::query()
            ->where(fn ($query) => $query->whereNull('platform_id')->orWhere('platform_id', $platformId))
            ->orderByRaw('CASE WHEN platform_id IS NULL THEN 0 ELSE 1 END')
            ->latest('id')
            ->first();
    }

    public function forViewer(Platform $platform, ?string $account): array
    {
        $amount = $platform->guarantee_amount_brl;
        $values = [
            $platform->name,
            $amount === null ? '—' : 'R$ '.number_format((float) $amount, 2, ',', '.'),
            $account ?? '—',
        ];
        $legacyPlaceholders = str_replace(['【', '】'], ['{{', '}}'], self::PLACEHOLDERS);
        $variables = array_combine(self::PLACEHOLDERS, $values) + array_combine($legacyPlaceholders, $values);

        return [
            'id' => $this->id,
            'platform_id' => $platform->id,
            'title' => strtr($this->title, $variables),
            'body' => strtr($this->body, $variables),
            'created_at' => $this->created_at,
        ];
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }
}
