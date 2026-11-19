<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MemberVerification
{
    public const MANAGE = 'system.manage-member-verification';

    public function smsEnabled(): bool
    {
        return (bool) DB::table('member_verification_settings')->where('id', 1)->firstOrFail()->sms_enabled;
    }

    public function requireSms(): void
    {
        abort_unless($this->smsEnabled(), 409, '短信验证已关闭。');
    }

    public function save(bool $enabled, User $operator): void
    {
        abort_unless($operator->hasPermission(self::MANAGE), 403);
        if (! $enabled && ! function_exists('imagepng')) {
            throw ValidationException::withMessages(['sms_enabled' => '请先在服务器启用 PHP GD，再切换到图片验证码。']);
        }

        DB::table('member_verification_settings')->where('id', 1)
            ->update(['sms_enabled' => $enabled, 'user_id' => $operator->id, 'updated_at' => now()]);
    }
}
