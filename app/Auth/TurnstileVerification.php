<?php

namespace App\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class TurnstileVerification
{
    public const RULES = ['turnstile_token' => ['required', 'string', 'max:2048']];

    public function __construct(private readonly MemberVerification $verification) {}

    public function validate(string $token, string $action, ?string $ip): void
    {
        $credentials = $this->verification->turnstileCredentials();
        $secret = $credentials['secret'] ?? null;
        abort_if(blank($secret), 503, 'Cloudflare 验证暂不可用。');

        try {
            $result = Http::asForm()->connectTimeout(3)->timeout(6)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array_filter([
                    'secret' => $secret, 'response' => $token, 'remoteip' => $ip,
                ]));
        } catch (ConnectionException) {
            abort(503, 'Cloudflare 验证暂不可用。');
        }

        abort_unless($result->successful() && is_array($result->json()), 503, 'Cloudflare 验证暂不可用。');
        $hostname = $credentials['hostname'] ?? null;
        if ($result->json('success') !== true || $result->json('action') !== $action
            || (filled($hostname) && strcasecmp((string) $result->json('hostname'), $hostname) !== 0)
            || ! Cache::add('turnstile.used.'.hash('sha256', $token), true, now()->addMinutes(5))) {
            throw ValidationException::withMessages(['turnstile_token' => 'Cloudflare 验证无效或已过期，请重新验证。']);
        }
    }
}
