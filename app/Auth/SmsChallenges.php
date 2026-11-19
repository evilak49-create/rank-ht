<?php

namespace App\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SmsChallenges
{
    public function __construct(private readonly ?SmsSender $sender = null) {}

    public function send(string $phone, string $purpose): void
    {
        abort_if($this->sender === null, 503, '短信服务暂不可用。');

        $code = (string) random_int(100000, 999999);
        $digest = $this->digest($phone, $purpose, $code);
        DB::table('sms_challenges')->upsert([
            'phone' => $phone,
            'purpose' => $purpose,
            'digest' => $digest,
            'expires_at' => now()->addMinutes(5),
        ], ['phone', 'purpose'], ['digest', 'expires_at']);

        try {
            $this->sender->sendCode($phone, $purpose, $code);
        } catch (\Throwable $exception) {
            DB::table('sms_challenges')->where(compact('phone', 'purpose', 'digest'))->delete();

            throw $exception;
        }
    }

    public function verify(string $phone, string $purpose, string $code): void
    {
        $challenge = DB::table('sms_challenges')->where(['phone' => $phone, 'purpose' => $purpose])
            ->where('expires_at', '>', now())->first();
        $this->assertValid($challenge, $phone, $purpose, $code);
    }

    // Call within the transaction that performs the verified operation.
    public function consume(string $phone, string $purpose, string $code): void
    {
        $query = DB::table('sms_challenges')->where(['phone' => $phone, 'purpose' => $purpose]);
        $challenge = (clone $query)->where('expires_at', '>', now())->lockForUpdate()->first();
        $this->assertValid($challenge, $phone, $purpose, $code);
        $query->delete();
    }

    private function assertValid(?object $challenge, string $phone, string $purpose, string $code): void
    {
        if ($challenge === null || ! hash_equals($challenge->digest, $this->digest($phone, $purpose, $code))) {
            throw ValidationException::withMessages(['code' => '短信验证码无效或已过期。']);
        }
    }

    private function digest(string $phone, string $purpose, string $code): string
    {
        return hash_hmac('sha256', "$phone:$purpose:$code", config('app.key'));
    }
}
