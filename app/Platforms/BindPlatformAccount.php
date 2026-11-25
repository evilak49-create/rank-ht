<?php

namespace App\Platforms;

use App\Auth\MemberVerification;
use App\Auth\SmsChallenges;
use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Models\PlatformBindingAttempt;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BindPlatformAccount
{
    public function __construct(
        private readonly PlatformAdministration $platforms,
        private readonly SmsChallenges $sms,
        private readonly MemberVerification $verification,
    ) {}

    public function sendCode(Member $member, Platform $platform, string $phone): void
    {
        $this->verification->requireSms();
        abort_unless($platform->status() === 'available', 404);
        $this->ensureNotBound($member, $platform);
        $this->sms->send($phone, $this->purpose($member, $platform));
    }

    public function bind(
        Member $member, Platform $platform, string $phone, ?string $code, string $mode, ?PlatformBindingAttempt $attempt = null,
    ): PlatformBinding {
        $useSms = $mode === 'sms';
        abort_unless($platform->status() === 'available', 404);
        $this->ensureNotBound($member, $platform);
        abort_if($this->verification->mode() !== $mode, 409, '验证方式已更新，请重新获取验证码。');
        $purpose = $this->purpose($member, $platform);
        if ($useSms && $this->requiresVerification($member, $phone)) {
            $this->sms->verify($phone, $purpose, $this->requiredCode($code));
        }

        try {
            $account = $platform->network->queryDriver()->lookup($platform->active_query, $phone);
        } catch (PlatformQueryFailure $failure) {
            $this->platforms->recordQueryOutcome($platform, $failure->kind);
            if ($failure->kind === 'ambiguous') {
                throw ValidationException::withMessages(['phone' => $failure->getMessage()]);
            }
            abort(503, '合作平台查询暂不可用。');
        }
        $this->platforms->recordQueryOutcome($platform, $account === null ? 'not_found' : 'passed');
        if ($account === null) {
            throw ValidationException::withMessages(['phone' => PlatformQueryFailure::label('not_found')]);
        }

        return $this->complete($member, $platform, $phone, $account, function (Member $owner) use ($member, $phone, $code, $purpose, $useSms, $mode): void {
            abort_if($owner->session_version !== $member->session_version, 401, '会员登录会话已失效。');
            abort_if($this->verification->mode() !== $mode, 409, '验证方式已更新，请重新获取验证码。');
            if ($useSms && $this->requiresVerification($owner, $phone)) {
                $this->sms->consume($phone, $purpose, $this->requiredCode($code));
                if ($owner->phone === $phone) {
                    $owner->forceFill(['phone_verified_at' => now()])->save();
                }
            }
        }, $attempt);
    }

    public function complete(
        Member $member, Platform $platform, string $phone, PlatformAccount $account, Closure $authorize, ?PlatformBindingAttempt $attempt = null,
    ): PlatformBinding {
        try {
            return DB::transaction(function () use ($member, $platform, $phone, $account, $authorize, $attempt): PlatformBinding {
                $current = Platform::query()->lockForUpdate()->findOrFail($platform->id);
                abort_unless($current->status() === 'available', 404);
                abort_if($current->network !== $platform->network || $current->active_query_revision !== $platform->active_query_revision, 409, '平台配置已更新，请重试。');

                $owner = Member::query()->lockForUpdate()->findOrFail($member->id);
                abort_if($owner->disabled_at !== null, 403, '会员账号已禁用。');
                $this->ensureNotBound($owner, $current);

                $authorize($owner, $current);

                $binding = $current->bindings()->create([
                    'member_id' => $owner->id, 'platform_account_id' => $account->id, 'query_phone' => $phone,
                    'account_name' => $attempt?->method === 'automatic' ? $phone : null,
                    'baseline_deposit_cents' => $account->depositCents, 'baseline_valid_bet_cents' => $account->validBetCents,
                    'baseline_observed_at' => $account->observedAt,
                    'rescue_baseline' => $account->rescueSnapshot(),
                    'protection_status' => $current->guarantee_amount_brl > 0 ? 'active' : 'inactive',
                ]);
                $attempt?->succeed($binding);

                return $binding;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, '该平台账号已绑定。');
        }
    }

    private function ensureNotBound(Member $member, Platform $platform): void
    {
        abort_if($platform->bindings()->where('member_id', $member->id)->exists(), 409, '你已绑定该平台。');
    }

    private function requiresVerification(Member $member, string $phone): bool
    {
        return $member->phone !== $phone || $member->phone_verified_at === null;
    }

    private function requiredCode(?string $code): string
    {
        if ($code === null) {
            throw ValidationException::withMessages(['code' => '请验证本次查询使用的手机号。']);
        }

        return $code;
    }

    private function purpose(Member $member, Platform $platform): string
    {
        return 'pb:'.substr(hash('sha256', $member->id.':'.$platform->id), 0, 29);
    }
}
