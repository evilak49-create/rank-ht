<?php

namespace App\Models;

use App\Content\Publishing;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['platform_id', 'user_id', 'title', 'body', 'body_html'])]
class ProtectionTerm extends Model
{
    use SoftDeletes;

    public const PLACEHOLDERS = ['【平台名称】', '【保障金额】', '【会员账号】', '【保障状态】'];

    public static function currentForPlatform(int $platformId): ?self
    {
        $latest = static::withTrashed()
            ->where(fn ($query) => $query->whereNull('platform_id')->orWhere('platform_id', $platformId))
            ->orderByRaw('CASE WHEN platform_id IS NULL THEN 0 ELSE 1 END')
            ->latest('id')
            ->first();

        return $latest?->trashed() ? null : $latest;
    }

    public function forViewer(Platform $platform, ?PlatformBinding $binding): array
    {
        $amount = $platform->guarantee_amount_brl;
        $status = $binding === null ? '—' : PlatformBinding::protectionStatusLabel($binding->currentProtectionStatus());
        $values = [
            $platform->name,
            $amount === null ? '—' : 'R$ '.number_format((float) $amount, 2, ',', '.'),
            $binding?->accountName() ?? '—',
            $status,
        ];
        $legacyPlaceholders = str_replace(['【', '】'], ['{{', '}}'], self::PLACEHOLDERS);
        $variables = array_combine(self::PLACEHOLDERS, $values) + array_combine($legacyPlaceholders, $values);

        return [
            'id' => $this->id,
            'platform_id' => $platform->id,
            'title' => strtr($this->title, $variables),
            'body' => strtr($this->body, $variables),
            'body_html' => $this->body_html === null ? null : $this->renderHtml($variables),
            'created_at' => $this->created_at,
        ];
    }

    public function editorBody(): string
    {
        return $this->body_html === null
            ? '<p>'.nl2br(e($this->body), false).'</p>'
            : app(Publishing::class)->sanitize($this->body_html);
    }

    private function renderHtml(array $variables): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->loadHTML('<?xml encoding="UTF-8"><div id="terms-body">'.$this->editorBody().'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $body = $document->getElementById('terms-body');

        // Variables belong in displayed text, never in links or image URLs.
        foreach ((new DOMXPath($document))->query('.//text()', $body) as $node) {
            $node->nodeValue = strtr($node->nodeValue, $variables);
        }

        $html = '';
        foreach ($body->childNodes as $node) {
            $html .= $document->saveHTML($node);
        }

        return $html;
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }
}
