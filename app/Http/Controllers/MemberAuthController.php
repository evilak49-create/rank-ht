<?php

namespace App\Http\Controllers;

use App\Auth\ImageCaptcha;
use App\Auth\MemberPassword;
use App\Auth\MemberPhone;
use App\Auth\MemberVerification;
use App\Auth\RegistrationOrigin;
use App\Auth\SmsChallenges;
use App\Auth\TurnstileVerification;
use App\Jobs\ResolveRegistrationCountry;
use App\Models\Member;
use App\RedPackets\InvitationRewards;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Throwable;

class MemberAuthController extends Controller
{
    public function __construct(private readonly MemberVerification $verification) {}

    public function verification(Request $request): JsonResponse
    {
        $settings = $this->verification->settings();
        $mode = $settings->mode;

        return response()->json(['data' => [
            'mode' => $mode,
            'reset_mode' => $this->resetMode(Auth::guard('member')->user(), $mode),
            'can_reset_password' => true,
            'turnstile_site_key' => $mode === 'cloudflare' ? $this->verification->turnstileSiteKey($settings) : null,
        ]])->header('Cache-Control', 'private, no-store')->withCookie(RegistrationOrigin::fromRequest($request)->cookie());
    }

    public function captcha(Request $request, ImageCaptcha $captcha): JsonResponse
    {
        $data = $request->validate(['purpose' => 'required|in:register,reset_password,platform_binding']);
        $current = Auth::guard('member')->user();
        $mode = $this->verification->mode();
        abort_if($data['purpose'] === 'platform_binding' && ! ($current instanceof Member), 401);
        $challengeMode = $data['purpose'] === 'reset_password' ? $this->resetMode($current, $mode) : $mode;
        abort_unless($challengeMode === 'image', 409, '当前验证方式未启用图片验证码。');

        return response()->json(['data' => $captcha->issue($request->session()->getId(), $data['purpose'])])
            ->header('Cache-Control', 'private, no-store');
    }

    public function sendCode(Request $request, SmsChallenges $sms): JsonResponse
    {
        $data = $this->validatePhone($request, ['purpose' => 'required|in:register,reset_password']);
        $mode = $this->verification->mode();
        $current = Auth::guard('member')->user();
        $challengeMode = $data['purpose'] === 'reset_password' ? $this->resetMode($current, $mode) : $mode;
        abort_unless($challengeMode === 'sms', 409, '当前验证方式未启用短信验证码。');
        if ($data['purpose'] === 'reset_password' && $current instanceof Member) {
            $this->validateResetAccount($request, $current->refresh(), $data['phone']);
        }
        $sms->send($data['phone'], $data['purpose']);

        return response()->json(['status' => 'accepted'], 202);
    }

    public function register(
        Request $request, SmsChallenges $sms, InvitationRewards $invitations, ImageCaptcha $captcha, TurnstileVerification $turnstile,
    ): JsonResponse {
        $mode = $this->verification->mode();
        $origin = RegistrationOrigin::fromRequest($request);
        $data = $this->validatePhone($request, [
            ...$this->challengeRules($mode),
            'password' => MemberPassword::RULE,
            'invite_code' => ['nullable', 'string', 'regex:/\A[0-9a-fA-F]{32}\z/'],
        ]);
        MemberPassword::validateBytes($data['password']);
        if ($mode === 'image') {
            $captcha->consume($request->session()->getId(), 'register', $data['challenge_id'], $data['code']);
        } elseif ($mode === 'cloudflare') {
            $turnstile->validate($data['turnstile_token'], 'register', $origin->ip);
        }

        try {
            $member = DB::transaction(function () use ($data, $sms, $invitations, $mode, $origin): Member {
                $settings = $this->verification->settings(lock: true);
                abort_if($settings->mode !== $mode, 409, '验证方式已更新，请重新获取验证码。');
                $origin->enforceLimits($settings);
                if ($mode === 'sms') {
                    $sms->consume($data['phone'], 'register', $data['code']);
                }

                $member = Member::create([
                    'phone' => $data['phone'],
                    'password' => $data['password'],
                    'phone_verified_at' => $mode === 'sms' ? now() : null,
                    ...$origin->attributes(),
                ]);
                if (filled($data['invite_code'] ?? null)) {
                    $invitations->register($member, $data['invite_code']);
                }

                return $member;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['phone' => '该手机号已注册。']);
        }

        if ($origin->ip !== null && ($origin->country !== null
            || filter_var($origin->ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false)) {
            try {
                $job = new ResolveRegistrationCountry($member->id, $origin->country);
                if (in_array(config('queue.default'), ['sync', 'deferred', 'null'], true)) {
                    $job->onConnection('database');
                }
                Bus::dispatch($job);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return response()->json($this->memberData($member), 201)->withCookie($origin->cookie());
    }

    public function login(Request $request): JsonResponse
    {
        $data = $this->validatePhone($request, ['password' => 'required|string|max:72']);
        MemberPassword::validateBytes($data['password']);

        $member = DB::transaction(function () use ($data): Member {
            $member = Member::query()->where('phone', $data['phone'])->lockForUpdate()->first();
            if ($member === null || ! Hash::check($data['password'], $member->password)) {
                throw ValidationException::withMessages(['phone' => '手机号或密码错误。']);
            }

            if ($member->disabled_at === null) {
                $member->forceFill(['last_login_at' => now()])->save();
            }

            return $member;
        }, 3);

        if ($member->disabled_at !== null) {
            return response()->json(['code' => 'member_disabled', 'message' => '会员账号已禁用。'], 403);
        }

        Auth::guard('member')->login($member);
        $request->session()->regenerateToken();

        return response()->json($this->memberData($member));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->memberData($request->user()));
    }

    public function logout(Request $request): Response
    {
        Auth::guard('member')->logout();
        $request->session()->forget('member_session_version');
        $request->session()->regenerate(true);

        return response()->noContent();
    }

    public function resetPassword(
        Request $request, SmsChallenges $sms, ImageCaptcha $captcha, TurnstileVerification $turnstile,
    ): Response {
        $current = Auth::guard('member')->user();
        $mode = $this->resetMode($current, $this->verification->mode());
        $useSms = $mode === 'sms';
        $data = $this->validatePhone($request, [
            ...$this->challengeRules($mode),
            'password' => MemberPassword::RULE,
            ...($useSms ? [] : ['current_password' => 'required|string|max:72']),
        ]);
        MemberPassword::validateBytes($data['password']);
        if (! $useSms) {
            MemberPassword::validateBytes($data['current_password']);
            $this->validateResetAccount($request, $current->refresh(), $data['phone']);
            if ($mode === 'cloudflare') {
                $turnstile->validate($data['turnstile_token'], 'reset_password', RegistrationOrigin::fromRequest($request)->ip);
            } else {
                $captcha->consume($request->session()->getId(), 'reset_password', $data['challenge_id'], $data['code']);
            }
        }

        DB::transaction(function () use ($request, $current, $data, $sms, $useSms, $mode): void {
            abort_if($this->resetMode($current, $this->verification->mode()) !== $mode, 409, '验证方式已更新，请重新获取验证码。');
            $query = Member::query()->lockForUpdate();
            if ($current instanceof Member) {
                $member = $query->findOrFail($current->id);
                $this->validateResetAccount($request, $member, $data['phone']);
            } else {
                $member = $query->where('phone', $data['phone'])->first();
            }
            if ($useSms) {
                $sms->consume($data['phone'], 'reset_password', $data['code']);
            } elseif (! Hash::check($data['current_password'], $member->password)) {
                throw ValidationException::withMessages(['current_password' => '当前密码错误。']);
            }

            $member?->forceFill([
                'password' => $data['password'],
                'session_version' => $member->session_version + 1,
                'remember_token' => null,
            ])->save();
            $member?->revokePlatformRegistrationIntents();
        }, 3);

        return $this->logout($request);
    }

    private function challengeRules(string $mode): array
    {
        return match ($mode) {
            'sms' => ['code' => 'required|digits:6'],
            'cloudflare' => TurnstileVerification::RULES,
            default => ImageCaptcha::RULES,
        };
    }

    private function resetMode(?Member $member, string $registrationMode): string
    {
        return $member === null ? 'sms' : $registrationMode;
    }

    private function validateResetAccount(Request $request, Member $member, string $phone): void
    {
        abort_if($member->disabled_at !== null, 403, '会员账号已禁用。');
        abort_unless($request->session()->get('member_session_version') === $member->session_version, 401, '会员登录会话已失效。');
        if ($member->phone !== $phone) {
            throw ValidationException::withMessages(['phone' => '手机号须与当前会员账号一致。']);
        }
    }

    private function validatePhone(Request $request, array $rules): array
    {
        $request->merge(['phone' => MemberPhone::normalize($request->input('phone'))]);

        return $request->validate(['phone' => ['required', MemberPhone::RULE], ...$rules]);
    }

    private function memberData(Member $member): array
    {
        return [
            'id' => $member->id,
            'phone' => $member->phone,
            'phone_verified_at' => $member->phone_verified_at?->toISOString(),
            'points_balance' => $member->points_balance ?? 0,
            'available_balance_cents' => $member->available_balance_cents ?? 0,
            'last_login_at' => $member->last_login_at?->toISOString(),
        ];
    }
}
