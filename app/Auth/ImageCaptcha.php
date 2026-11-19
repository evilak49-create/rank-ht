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
            $saved = Cache::lock($key.':lock', 5)->block(2, fn () => Cache::put($key, $this->digest($id, $answer), now()->addMinutes(3)));
        } catch (LockTimeoutException) {
            abort(409, '请稍后重新获取图片验证码。');
        }
        abort_unless($saved, 503, '图片验证码暂不可用。');

        return ['challenge_id' => $id, 'image' => $image, 'expires_in' => 180];
    }

    public function consume(string $session, string $purpose, string $id, string $answer): void
    {
        $key = $this->key($session, $purpose);
        try {
            $stored = Cache::lock($key.':lock', 5)->block(2, fn () => Cache::pull($key));
        } catch (LockTimeoutException) {
            abort(409, '请刷新图片验证码后重试。');
        }
        if (! is_string($stored) || ! hash_equals($stored, $this->digest(strtolower($id), strtoupper($answer)))) {
            throw ValidationException::withMessages(['code' => '图片验证码错误或已过期，请刷新后重试。']);
        }
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
