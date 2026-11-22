<?php

namespace App\Auth;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImageCaptcha
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const LENGTH = 4;

    private const TTL_SECONDS = 180;

    private const MAX_ACTIVE = 64;

    public const RULES = [
        'challenge_id' => 'required|uuid:4',
        'code' => ['required', 'string', 'regex:/\A[A-Za-z2-9]{'.self::LENGTH.'}\z/'],
    ];

    public function __construct(private readonly CaptchaImage $image) {}

    public function issue(string $session, string $purpose): array
    {
        $answer = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $answer .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $id = (string) Str::uuid();
        $image = $this->image->render($answer);
        $key = $this->key($session, $purpose);
        try {
            $saved = Cache::lock($key.':lock', 5)->block(2, function () use ($key, $id, $answer): bool {
                $now = now()->timestamp;
                $stored = Cache::get($key);
                $active = is_array($stored) ? $this->active($stored, $now) : [];
                $active[$id] = ['digest' => $this->digest($id, $answer), 'expires_at' => $now + self::TTL_SECONDS];

                return Cache::put($key, array_slice($active, -self::MAX_ACTIVE, preserve_keys: true), self::TTL_SECONDS);
            });
        } catch (LockTimeoutException) {
            abort(409, '请稍后重新获取图片验证码。');
        }
        abort_unless($saved, 503, '图片验证码暂不可用。');

        return ['challenge_id' => $id, 'image' => $image, 'expires_in' => self::TTL_SECONDS];
    }

    public function consume(string $session, string $purpose, string $id, string $answer): void
    {
        $id = strtolower($id);
        $key = $this->key($session, $purpose);
        try {
            $stored = Cache::lock($key.':lock', 5)->block(2, function () use ($key, $id): ?string {
                $challenges = Cache::get($key);
                if (is_string($challenges)) {
                    return Cache::forget($key) ? $challenges : null;
                }
                $active = is_array($challenges) ? $this->active($challenges, now()->timestamp) : [];
                if (! isset($active[$id])) {
                    return null;
                }

                $digest = $active[$id]['digest'];
                unset($active[$id]);
                $updated = $active === [] ? Cache::forget($key) : Cache::put($key, $active, self::TTL_SECONDS);
                abort_unless($updated, 503, '图片验证码暂不可用。');

                return $digest;
            });
        } catch (LockTimeoutException) {
            abort(409, '请刷新图片验证码后重试。');
        }
        if (! is_string($stored) || ! hash_equals($stored, $this->digest($id, strtoupper($answer)))) {
            throw ValidationException::withMessages(['code' => '图片验证码错误或已过期，请刷新后重试。']);
        }
    }

    private function active(array $challenges, int $now): array
    {
        return array_filter($challenges, fn (array $challenge): bool => $challenge['expires_at'] > $now);
    }

    private function key(string $session, string $purpose): string
    {
        return 'member-image-captcha:'.hash('sha256', $session.':'.$purpose);
    }

    private function digest(string $id, string $answer): string
    {
        return hash_hmac('sha256', $id.':'.$answer, config('app.key'));
    }
}
