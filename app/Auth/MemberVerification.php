<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MemberVerification
{
    public const MANAGE = 'system.manage-member-verification';

    public const MODES = ['sms' => '短信验证码', 'image' => '图片验证码', 'cloudflare' => 'Cloudflare 验证'];

    public function settings(bool $lock = false): object
    {
        $query = DB::table('member_verification_settings')->where('id', 1);
        $row = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        $row->mode ??= $row->sms_enabled ? 'sms' : 'image';

        return $row;
    }

    public function mode(): string
    {
        return $this->settings()->mode;
    }

    public function requireSms(): void
    {
        abort_unless($this->mode() === 'sms', 409, '短信验证已关闭。');
    }

    public function save(array $settings, User $operator): void
    {
        abort_unless($operator->hasPermission(self::MANAGE), 403);
        $values = Validator::make($settings, [
            'mode' => ['required', Rule::in(array_keys(self::MODES))],
            'ip_registration_limit' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'device_registration_limit' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
        ])->validate();
        if ($values['mode'] === 'image' && ! function_exists('imagepng')) {
            throw ValidationException::withMessages(['mode' => '请先在服务器启用 PHP GD，再切换到图片验证码。']);
        }
        if ($values['mode'] === 'cloudflare' && (blank(config('services.turnstile.site_key')) || blank(config('services.turnstile.secret')))) {
            throw ValidationException::withMessages(['mode' => '请先在服务器配置 Cloudflare Turnstile 密钥。']);
        }

        DB::table('member_verification_settings')->where('id', 1)
            ->update([
                'mode' => $values['mode'], 'sms_enabled' => $values['mode'] === 'sms',
                'ip_registration_limit' => $values['ip_registration_limit'] ?? null,
                'device_registration_limit' => $values['device_registration_limit'] ?? null,
                'user_id' => $operator->id, 'updated_at' => now(),
            ]);
    }
}
