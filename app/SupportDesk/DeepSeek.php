<?php

namespace App\SupportDesk;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use SensitiveParameter;

class DeepSeek
{
    public const ENDPOINT = 'https://api.deepseek.com/chat/completions';

    public const MODELS = ['deepseek-flash' => 'DeepSeek Flash', 'deepseek-v4-pro' => 'DeepSeek V4 Pro'];

    public function complete(string $model, #[SensitiveParameter] string $key, array $messages, int $maxTokens, bool $json = false, int $timeout = 12): string
    {
        $response = Http::asJson()->acceptJson()->withToken($key)->withoutRedirecting()->connectTimeout(4)->timeout($timeout)
            ->post(self::ENDPOINT, [
                'model' => $model, 'messages' => $messages, 'max_tokens' => $maxTokens,
                'thinking' => ['type' => 'disabled'], 'stream' => false,
                ...($json ? ['response_format' => ['type' => 'json_object']] : []),
            ]);
        $content = $response->json('choices.0.message.content');
        if (! $response->successful() || $response->json('choices.0.finish_reason') !== 'stop' || ! is_string($content) || trim($content) === '') {
            throw new RuntimeException('DeepSeek did not return a complete answer.');
        }

        return trim($json ? $content : strip_tags($content));
    }
}
