<?php

namespace App\Http\Controllers;

use App\Auth\ImageCaptcha;
use App\Auth\MemberPassword;
use App\Auth\MemberPhone;
use App\Auth\MemberVerification;
use App\Auth\SmsChallenges;
use App\Models\Member;
use App\RedPackets\InvitationRewards;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MemberAuthController extends Controller
{
    public function __construct(private readonly MemberVerification $verification) {}

    public function verification(): JsonResponse
    {
        $sms = $this->verification->smsEnabled();

        return response()->json(['data' => [
            'mode' => $sms ? 'sms' : 'image',
            'can_reset_password' => $sms || Auth::guard('member')->check(),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function captcha(Request $request, ImageCaptcha $captcha): JsonResponse
    {
        abort_if($this->verification->smsEnabled(), 409, '当前使用短信验证码。');
        $data = $request->validate(['purpose' => 'required|in:register,reset_password,platform_binding']);
        abort_if($data['purpose'] === 'platform_binding' && ! Auth::guard('member')->check(), 401);
        abort_if($data['purpose'] === 'reset_password' && ! Auth::guard('member')->check(), 409, '短信找回暂不可用，请联系人工客服。');

        return response()->json(['data' => $captcha->issue($request->session()->getId(), $data['purpose'])])
            ->header('Cache-Control', 'private, no-store');
    }

    public function sendCode(Request $request, SmsChallenges $sms): JsonResponse
    {
        $this->verification->requireSms();
        $data = $this->validatePhone($request, ['purpose' => 'required|in:register,reset_password']);
        if ($data['purpose'] === 'reset_password' && ($current = Auth::guard('member')->user()) instanceof Member) {
            $this->validateResetAccount($request, $current->refresh(), $data['phone']);
        }
        $sms->send($data['phone'], $data['purpose']);

        return response()->json(['status' => 'accepted'], 202);
    }

    public function register(Request $request, SmsChallenges $sms, InvitationRewards $invitations, ImageCaptcha $captcha): JsonResponse
    {
        $useSms = $this->verification->smsEnabled();
        $data = $this->validatePhone($request, [
            ...$this->challengeRules($useSms),
            'password' => MemberPassword::RULE,
            'invite_code' => ['nullable', 'string', 'regex:/\A[0-9a-fA-F]{32}\z/'],
        ]);
        MemberPassword::validateBytes($data['password']);
        if (! $useSms) {
            $captcha->consume($request->session()->getId(), 'register', $data['challenge_id'], $data['code']);
        }

        try {
            $member = DB::transaction(function () use ($data, $sms, $invitations, $useSms): Member {
                abort_if($this->verification->smsEnabled() !== $useSms, 409, '验证方式已更新，请重新获取验证码。');
                if ($useSms) {
                    $sms->consume($data['phone'], 'register', $data['code']);
                }

                $member = Member::create([
                    'phone' => $data['phone'],
                    'password' => $data['password'],
                    'phone_verified_at' => $useSms ? now() : null,
                ]);
                if (filled($data['invite_code'] ?? null)) {
                    $invitations->register($member, $data['invite_code']);
                }

                return $member;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['phone' => '该手机号已注册。']);
        }

        return response()->json($this->memberData($member), 201);
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
        $request->session()->put('member_session_version', $member->session_version);
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

    public function resetPassword(Request $request, SmsChallenges $sms, ImageCaptcha $captcha): Response
    {
        $useSms = $this->verification->smsEnabled();
        $current = Auth::guard('member')->user();
        abort_if(! $useSms && ! ($current instanceof Member), 409, '短信找回暂不可用，请联系人工客服。');
        $data = $this->validatePhone($request, [
            ...$this->challengeRules($useSms),
            'password' => MemberPassword::RULE,
            ...($useSms ? [] : ['current_password' => 'required|string|max:72']),
        ]);
        MemberPassword::validateBytes($data['password']);
        if (! $useSms) {
            MemberPassword::validateBytes($data['current_password']);
            $this->validateResetAccount($request, $current->refresh(), $data['phone']);
            $captcha->consume($request->session()->getId(), 'reset_password', $data['challenge_id'], $data['code']);
        }

        DB::transaction(function () use ($request, $current, $data, $sms, $useSms): void {
            abort_if($this->verification->smsEnabled() !== $useSms, 409, '验证方式已更新，请重新获取验证码。');
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
            ])->save();
        }, 3);

        return $this->logout($request);
    }

    private function challengeRules(bool $useSms): array
    {
        return $useSms ? ['code' => 'required|digits:6'] : ImageCaptcha::RULES;
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
