<?php

namespace App\Content;

use App\Models\Platform;
use App\Models\SiteArticle;
use App\Models\SiteBanner;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Publishing
{
    public const TARGETS = [
        'home' => '首页', 'platforms' => '平台列表', 'platform' => '平台详情',
        'announcements' => '公告列表', 'help' => '帮助中心', 'help_article' => '帮助文章',
    ];

    public function article(string $type, ?SiteArticle $existing, array $data, User $operator): SiteArticle
    {
        abort_unless($operator->hasPermission('content.manage'), 403);
        abort_unless(in_array($type, ['announcement', 'help'], true) && ($existing === null || $existing->type === $type), 404);

        $rules = [
            'title' => 'required|string|max:150', 'body' => 'required|string|max:12000',
            'published' => 'required|boolean', 'sort_order' => 'nullable|integer|between:0,100000',
            'published_at' => 'nullable|date',
        ];
        if ($type === 'help') {
            $rules += [
                'steps' => 'nullable|array|max:30',
                'steps.*' => 'array:title,body,image_url',
                'steps.*.title' => 'required|string|max:150', 'steps.*.body' => 'required|string|max:1500',
                'steps.*.image_url' => 'nullable|string|max:2048|url:https',
                'target_type' => ['nullable', Rule::in(array_keys(self::TARGETS))],
                'target_id' => 'nullable|integer|min:1',
            ];
        }
        $fields = Validator::make($data, $rules)->validate();
        $record = $existing ?? new SiteArticle;
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

    public function banner(?SiteBanner $existing, array $data, User $operator): SiteBanner
    {
        abort_unless($operator->hasPermission('content.manage'), 403);
        $fields = Validator::make($data, [
            'name' => 'required|string|max:150', 'image_url' => 'required|string|max:2048|url:https',
            'active' => 'required|boolean', 'sort_order' => 'required|integer|between:0,100000',
            'target_type' => ['required', Rule::in(array_keys(self::TARGETS))],
            'target_id' => 'nullable|integer|min:1',
        ])->validate();
        $record = $existing ?? new SiteBanner;
        $record->fill([
            ...$fields, 'user_id' => $operator->id,
            'target_id' => $this->validateTarget($fields['target_type'], $fields['target_id'] ?? null),
        ])->save();

        return $record;
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
