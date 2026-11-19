<?php

namespace App\SupportDesk;

use App\Models\AiSetting;
use App\Models\SiteArticle;
use App\Models\SupportFaq;
use App\Models\SupportSetting;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class CustomerSupport
{
    public function __construct(private readonly AiConfiguration $ai, private readonly DeepSeek $provider) {}

    public function settings(): SupportSetting
    {
        return SupportSetting::findOrFail(1);
    }

    public function publicSettings(string $language): array
    {
        $settings = $this->settings();
        $enabled = $settings->ai_enabled && $this->ai->active() !== null;
        $published = $enabled ? SupportFaq::query()->where('published', true)->whereKey($settings->recommended_ids)->get()->keyBy('id') : collect();

        return [
            'mode' => $enabled ? 'chat' : 'human', 'welcome' => $enabled ? $settings->welcome : null,
            'suggestions' => collect($settings->recommended_ids)->map(fn ($id) => $published->get($id)?->only(['id', 'question']))->filter()->values(),
            ...$this->human($settings, $language),
        ];
    }

    public function saveSettings(array $input, User $operator): void
    {
        abort_unless($operator->hasPermission('support.manage'), 403);
        $fields = Validator::make($input, [
            'ai_enabled' => ['required', 'boolean'],
            'welcome' => ['nullable', 'string', 'max:400'],
            'recommended_ids' => ['array', 'list', 'max:8'],
            'recommended_ids.*' => ['integer', 'distinct', 'exists:support_faqs,id'],
            'channels' => ['array', 'list', 'max:10'],
            'channels.*' => ['required', 'array:name,contact,url,enabled'],
            'channels.*.name' => ['required', 'string', 'max:80'],
            'channels.*.contact' => ['nullable', 'required_without:channels.*.url', 'string', 'max:150'],
            'channels.*.url' => ['nullable', 'string', 'max:2048', 'url:https'],
            'channels.*.enabled' => ['required', 'boolean'],
        ])->validate();
        if ($fields['ai_enabled'] && $this->ai->active() === null) {
            throw ValidationException::withMessages(['ai_enabled' => '请先在系统设置验证并保存 DeepSeek 配置。']);
        }

        $this->settings()->fill($fields + ['recommended_ids' => [], 'channels' => [], 'user_id' => $operator->id])->save();
    }

    public function saveFaq(?SupportFaq $existing, array $input, User $operator): SupportFaq
    {
        abort_unless($operator->hasPermission('support.manage'), 403);
        $fields = Validator::make($input, [
            'question' => ['required', 'string', 'max:200'],
            'aliases' => ['array', 'list', 'max:15'],
            'aliases.*' => ['required', 'string', 'max:200', 'distinct'],
            'answer' => ['required', 'string', 'max:4000'],
            'published' => ['required', 'boolean'],
            'help_article_id' => ['nullable', 'integer', Rule::exists('site_articles', 'id')->where('type', 'help')],
        ])->validate();
        $faq = $existing ?? new SupportFaq;
        $faq->fill($fields + ['aliases' => [], 'user_id' => $operator->id])->save();

        return $faq;
    }

    public function ask(string $question, string $language): array
    {
        $settings = $this->settings();
        $config = $this->ai->active();
        abort_unless($settings->ai_enabled && $config !== null, 409, 'AI 客服已停用。');

        return $this->reply($question, $language, $settings, $config);
    }

    public function preview(string $question, string $language, User $operator): array
    {
        abort_unless($operator->hasPermission('support.manage'), 403);

        return $this->reply($question, $language, $this->settings(), $this->ai->active());
    }

    private function reply(string $question, string $language, SupportSetting $settings, ?AiSetting $config): array
    {
        $normalized = $this->normalize($question);
        $link = $this->personalLink($normalized);
        if ($link !== null) {
            return [
                'type' => 'personal', 'link' => $link,
                'answer' => $language === 'zh-CN' ? '请登录后查看自己的业务记录。' : 'Entre na sua conta para consultar seus registros.',
            ];
        }
        $faq = $this->matchFaq($normalized);
        if ($faq === null) {
            return ['type' => 'human', ...$this->human($settings, $language)];
        }

        $answer = $faq->answer;
        if ($config !== null) {
            try {
                $answer = $this->provider->complete($config->model, $config->api_key, [
                    ['role' => 'system', 'content' => 'Rephrase the published FAQ answer in '.($language === 'zh-CN' ? 'Simplified Chinese' : 'Brazilian Portuguese').'. Preserve the facts and steps. Use plain text and add no links or facts.'],
                    ['role' => 'user', 'content' => json_encode($faq->only(['question', 'answer']), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
                ], 600) ?: $answer;
            } catch (Throwable) {
                // The published standard answer remains available during provider errors.
            }
        }
        $article = SiteArticle::query()->public()->where('type', 'help')->find($faq->help_article_id);

        return [
            'type' => 'faq', 'faq_id' => $faq->id, 'matched_question' => $faq->question, 'answer' => $answer,
            'help' => $article === null ? null : ['title' => $article->title, 'link' => '/help/'.$article->id],
        ];
    }

    private function human(SupportSetting $settings, string $language): array
    {
        $channels = collect($settings->channels)->filter(fn (array $channel): bool => (bool) $channel['enabled'])
            ->map(fn (array $channel): array => [
                'name' => $channel['name'], 'contact' => $channel['contact'] ?? null, 'url' => $channel['url'] ?? null,
            ])->values()->all();
        $answer = $channels === []
            ? ['zh-CN' => '人工客服暂不可用。', 'pt-BR' => 'O atendimento humano está indisponível.']
            : ['zh-CN' => '请选择一个人工客服渠道。', 'pt-BR' => 'Escolha um canal de atendimento.'];

        return ['channels' => $channels, 'answer' => $answer[$language]];
    }

    private function matchFaq(string $question): ?SupportFaq
    {
        $best = null;
        $highest = 0;
        foreach (SupportFaq::query()->where('published', true)->orderBy('id')->lazyById() as $faq) {
            foreach ([$faq->question, ...$faq->aliases] as $phrase) {
                $phrase = $this->normalize($phrase);
                $length = mb_strlen($phrase);
                $score = match (true) {
                    $length === 0 => 0,
                    $question === $phrase => 1000 + $length,
                    $length >= 4 && str_contains($question, $phrase) => $length,
                    default => 0,
                };
                if ($score > $highest) {
                    $highest = $score;
                    $best = $faq;
                }
            }
        }

        return $best;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($value);

        return trim(preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? '');
    }

    private function personalLink(string $question): ?string
    {
        if (! preg_match('/我的|余额|进度|状态|\b(?:meu|meus|minha|minhas|saldo|status|andamento)\b/u', $question)) {
            return null;
        }

        return match (true) {
            (bool) preg_match('/提现|\b(?:saque|saques|pix)\b/u', $question) => '/withdrawals',
            (bool) preg_match('/订单|\b(?:pedido|pedidos|compra|compras)\b/u', $question) => '/orders',
            (bool) preg_match('/积分|\b(?:ponto|pontos)\b/u', $question) => '/wallet',
            default => null,
        };
    }
}
