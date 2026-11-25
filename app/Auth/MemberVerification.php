<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
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

    public function turnstileCredentials(): array
    {
        $row = $this->settings();

        return $row->turnstile_secret === null
            ? config('services.turnstile', [])
            : ['secret' => Crypt::decryptString($row->turnstile_secret), 'hostname' => $row->turnstile_hostname];
    }

    public function turnstileSiteKey(object $row): ?string
    {
        return $row->turnstile_site_key ?? config('services.turnstile.site_key');
    }

    public function save(array $settings, User $operator): void
    {
        abort_unless($operator->hasPermission(self::MANAGE), 403);
        $values = Validator::make($settings, [
            'mode' => ['required', Rule::in(array_keys(self::MODES))],
            'ip_registration_limit' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'device_registration_limit' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'turnstile_site_key' => ['nullable', 'string', 'max:255', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'turnstile_secret' => ['nullable', 'string', 'max:512', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'turnstile_hostname' => ['nullable', 'string', 'max:253', 'regex:/\A(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\z/'],
        ])->validate();
        if ($values['mode'] === 'image' && ! function_exists('imagepng')) {
            throw ValidationException::withMessages(['mode' => '请先在服务器启用 PHP GD，再切换到图片验证码。']);
        }

        DB::transaction(function () use ($values, $operator): void {
            $row = $this->settings(lock: true);
            $siteKey = $values['turnstile_site_key'] ?? $row->turnstile_site_key;
            $hostname = $values['turnstile_hostname'] ?? $row->turnstile_hostname;
            $providedSecret = $values['turnstile_secret'] ?? null;
            $encryptedSecret = $row->turnstile_secret;

            if (filled($siteKey) || filled($hostname) || filled($providedSecret)) {
                if (blank($siteKey) || blank($hostname)) {
                    throw ValidationException::withMessages(blank($siteKey)
                        ? ['turnstile_site_key' => '请输入 Cloudflare Site Key。']
                        : ['turnstile_hostname' => '请输入前台域名，例如 rank.example.com。']);
                }
                if (blank($providedSecret) && (blank($encryptedSecret) || $siteKey !== $row->turnstile_site_key)) {
                    throw ValidationException::withMessages(['turnstile_secret' => '首次配置或更换 Site Key 时，请输入对应的 Secret Key。']);
                }
            }
            if (filled($providedSecret)) {
                $encryptedSecret = Crypt::encryptString($providedSecret);
            }

            if ($values['mode'] === 'cloudflare' && blank($encryptedSecret)
                && (blank(config('services.turnstile.site_key')) || blank(config('services.turnstile.secret')))) {
                throw ValidationException::withMessages(['mode' => '请先填写 Cloudflare Turnstile 配置。']);
            }

            DB::table('member_verification_settings')->where('id', 1)->update([
                'mode' => $values['mode'], 'sms_enabled' => $values['mode'] === 'sms',
                'ip_registration_limit' => $values['ip_registration_limit'] ?? null,
                'device_registration_limit' => $values['device_registration_limit'] ?? null,
                'turnstile_site_key' => $siteKey,
                'turnstile_hostname' => $hostname === null ? null : strtolower($hostname),
                'turnstile_secret' => $encryptedSecret,
                'user_id' => $operator->id, 'updated_at' => now(),
            ]);
        }, 3);
    }
}
