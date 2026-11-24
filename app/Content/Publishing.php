<?php

namespace App\Content;

use App\Models\Platform;
use App\Models\SiteArticle;
use App\Models\SiteBanner;
use App\Models\User;
use App\Storage\PublicImages;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

class Publishing
{
    public const TARGETS = [
        'home' => '首页', 'platforms' => '平台列表', 'platform' => '平台详情',
        'announcements' => '公告列表', 'help' => '帮助中心', 'help_article' => '帮助文章',
    ];

    public function __construct(private readonly PublicImages $images) {}

    public function article(string $type, ?SiteArticle $existing, array $data, User $operator): SiteArticle
    {
        abort_unless($operator->hasPermission('content.manage'), 403);
        abort_unless(in_array($type, ['announcement', 'help'], true) && ($existing === null || $existing->type === $type), 404);

        $files = [];
        if ($type === 'help' && is_array($data['steps'] ?? null)) {
            Validator::make($data, ['steps' => 'array|list|max:30'])->validate();
            foreach (array_keys($data['steps']) as $index) {
                $files["steps.$index.image_upload"] = "steps.$index.image_url";
            }
        }

        return $this->images->applyMany($data, $files, fn (array $fields) => $this->persistArticle($type, $existing, $fields, $operator));
    }

    private function persistArticle(string $type, ?SiteArticle $existing, array $data, User $operator): SiteArticle
    {
        $rules = [
            'title' => 'required|string|max:150', 'body' => 'required_without:body_html|string|max:12000',
            'body_html' => 'sometimes|required|string|max:30000',
            'published' => 'required|boolean', 'sort_order' => 'nullable|integer|between:0,100000',
            'published_at' => 'nullable|date',
        ];
        if ($type === 'help') {
            $rules += [
                'steps' => 'nullable|array|max:30',
                'steps.*' => 'array:title,body,image_url',
                'steps.*.title' => 'required|string|max:150', 'steps.*.body' => 'required|string|max:1500',
                'steps.*.image_url' => PublicImages::urlRules(),
                'target_type' => ['nullable', Rule::in(array_keys(self::TARGETS))],
                'target_id' => 'nullable|integer|min:1',
            ];
        }
        $fields = Validator::make($data, $rules)->validate();
        $record = $existing ?? new SiteArticle;
        if (isset($fields['body_html'])) {
            $fields['body_html'] = $this->sanitize($fields['body_html']);
            $plain = trim(html_entity_decode(strip_tags($fields['body_html']), ENT_QUOTES, 'UTF-8'));
            if ($plain === '' && ! str_contains($fields['body_html'], '<img ')) {
                throw ValidationException::withMessages(['body_html' => '请输入正文内容。']);
            }
            $fields['body'] = mb_substr($plain, 0, 12000);
            $fields['steps'] = null;
        }
        $target = $this->validateTarget($fields['target_type'] ?? null, $fields['target_id'] ?? null);
        if ($target !== null && $fields['target_type'] === 'help_article' && (string) $record->id === (string) $target) {
            throw ValidationException::withMessages(['target_id' => '站内入口不能链接到当前文章。']);
        }

        $record->fill([
            ...Arr::except($fields, ['target_id']), 'type' => $type, 'user_id' => $operator->id,
            'sort_order' => $fields['sort_order'] ?? 0, 'target_id' => $target,
            'published_at' => $fields['published_at'] ?? ($fields['published'] ? ($record->published_at ?? now()) : null),
        ])->save();

        return $record;
    }

    public function publish(SiteArticle $article, User $operator): void
    {
        abort_unless($operator->hasPermission('content.manage'), 403);
        $article->newQuery()->whereKey($article->id)->where(function ($query): void {
            $query->where('published', false)->orWhereNull('published_at')->orWhere('published_at', '>', now());
        })->update(['published' => true, 'published_at' => now(), 'user_id' => $operator->id]);
    }

    public function editorBody(SiteArticle $article): string
    {
        if ($article->body_html !== null) {
            return $this->sanitize($article->body_html);
        }

        $html = '<p>'.nl2br(e($article->body)).'</p>';
        foreach ($article->steps ?? [] as $step) {
            $html .= '<h3>'.e($step['title'] ?? '').'</h3><p>'.nl2br(e($step['body'] ?? '')).'</p>';
            if (filled($step['image_url'] ?? null)) {
                $html .= '<img src="'.e($step['image_url']).'" alt="'.e($step['title'] ?? '').'">';
            }
        }

        return $this->sanitize($html);
    }

    public function sanitize(string $html): string
    {
        $config = (new HtmlSanitizerConfig)->allowLinkSchemes(['https', 'http'])->allowRelativeLinks()
            ->allowMediaSchemes(['https'])->allowRelativeMedias()
            ->allowElement('a', ['href', 'title'])->allowElement('img', ['src', 'alt', 'title'])
            ->allowElement('ol', ['start']);
        foreach (['p', 'br', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'u', 's', 'blockquote', 'ul', 'li'] as $element) {
            $config = $config->allowElement($element);
        }

        return (new HtmlSanitizer($config))->sanitize($html);
    }

    public function banner(?SiteBanner $existing, array $data, User $operator): SiteBanner
    {
        abort_unless($operator->hasPermission('content.manage'), 403);

        return $this->images->apply($data, 'image_url', fn (array $fields) => $this->persistBanner($existing, $fields, $operator));
    }

    private function persistBanner(?SiteBanner $existing, array $data, User $operator): SiteBanner
    {
        $data['image_url'] ??= $existing?->image_url;
        if (array_key_exists('target_path', $data)) {
            $path = $this->bannerPath($data['target_path']);
            if ($path === null) {
                throw ValidationException::withMessages(['target_path' => '请填写站内链接，例如 #/announcements/12。']);
            }
            $data['target_path'] = $path;
            $data['target_type'] = 'home';
            $data['target_id'] = null;
        }
        $fields = Validator::make($data, [
            'name' => 'required|string|max:150', 'image_url' => PublicImages::urlRules(required: true),
            'active' => 'required|boolean', 'sort_order' => 'required|integer|between:0,100000',
            'target_type' => ['required', Rule::in(array_keys(self::TARGETS))],
            'target_id' => 'nullable|integer|min:1',
            'target_path' => 'nullable|string|max:1024',
        ])->validate();
        $record = $existing ?? new SiteBanner;
        $record->fill([
            ...$fields, 'user_id' => $operator->id,
            'target_id' => $this->validateTarget($fields['target_type'], $fields['target_id'] ?? null),
        ])->save();

        return $record;
    }

    public function bannerTarget(SiteBanner $banner): ?string
    {
        return $banner->target_path ?? $this->publicTarget($banner->target_type, $banner->target_id);
    }

    private function bannerPath(mixed $input): ?string
    {
        if (! is_string($input)) {
            return null;
        }
        $path = trim($input);
        if (str_starts_with($path, '#/')) {
            $path = substr($path, 1);
        } elseif (preg_match('~^https?://~i', $path)) {
            $target = parse_url($path);
            $site = parse_url(config('app.url'));
            if ($target === false || $site === false || isset($target['user']) || isset($target['pass'])
                || strcasecmp($target['host'] ?? '', $site['host'] ?? '') !== 0
                || ($target['scheme'] ?? null) !== ($site['scheme'] ?? null)
                || ($target['port'] ?? null) !== ($site['port'] ?? null)) {
                return null;
            }
            $path = $target['fragment'] ?? (($target['path'] ?? '/').(isset($target['query']) ? '?'.$target['query'] : ''));
        }

        return preg_match('~\A/(?!/)[^\\\\#\x00-\x20]*\z~', $path) && ! preg_match('~\A/%(?:2f|5c)~i', $path) ? $path : null;
    }

    public function publicTarget(?string $type, string|int|null $id): ?string
    {
        return match ($type) {
            'home' => '/',
            'platforms' => '/platforms',
            'announcements' => '/announcements',
            'help' => '/help',
            'platform' => Platform::query()->available()->whereKey($id)->exists() ? '/platforms/'.$id : null,
            'help_article' => SiteArticle::query()->public()->where('type', 'help')->whereKey($id)->exists() ? '/help/'.$id : null,
            default => null,
        };
    }

    private function validateTarget(?string $type, string|int|null $id): ?int
    {
        $exists = match ($type) {
            'platform' => $id !== null && Platform::query()->whereKey($id)->exists(),
            'help_article' => $id !== null && SiteArticle::query()->where('type', 'help')->whereKey($id)->exists(),
            default => null,
        };

        if ($exists === false) {
            throw ValidationException::withMessages(['target_id' => '请选择存在的站内目标。']);
        }

        return $exists === true ? (int) $id : null;
    }
}
