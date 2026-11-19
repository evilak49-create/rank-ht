<?php

namespace App\Http\Controllers;

use App\Auth\MemberPhone;
use App\Auth\SmsChallenges;
use App\Models\Member;
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
    public function sendCode(Request $request, SmsChallenges $sms): JsonResponse
    {
        $data = $this->validatePhone($request, ['purpose' => 'required|in:register,reset_password']);
        $sms->send($data['phone'], $data['purpose']);

        return response()->json(['status' => 'accepted'], 202);
    }

    public function register(Request $request, SmsChallenges $sms): JsonResponse
    {
        $data = $this->validatePhone($request, [
            'code' => 'required|digits:6',
            'password' => 'required|string|min:8|max:72',
        ]);
        $this->validatePasswordBytes($data['password']);

        try {
            $member = DB::transaction(function () use ($data, $sms): Member {
                $sms->consume($data['phone'], 'register', $data['code']);

                return Member::create([
                    'phone' => $data['phone'],
                    'password' => $data['password'],
                    'phone_verified_at' => now(),
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['phone' => '该手机号已注册。']);
        }

        return response()->json($this->memberData($member), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $this->validatePhone($request, ['password' => 'required|string|max:72']);
        $this->validatePasswordBytes($data['password']);

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

    public function resetPassword(Request $request, SmsChallenges $sms): Response
    {
        $data = $this->validatePhone($request, [
            'code' => 'required|digits:6',
            'password' => 'required|string|min:8|max:72',
        ]);
        $this->validatePasswordBytes($data['password']);

        $current = Auth::guard('member')->user();
        if ($current !== null && $current->phone !== $data['phone']) {
            throw ValidationException::withMessages(['phone' => '手机号须与当前会员账号一致。']);
        }

        DB::transaction(function () use ($data, $sms): void {
            $member = Member::query()->where('phone', $data['phone'])->lockForUpdate()->first();
            $sms->consume($data['phone'], 'reset_password', $data['code']);

            $member?->forceFill([
                'password' => $data['password'],
                'session_version' => $member->session_version + 1,
            ])->save();
        }, 3);

        return $this->logout($request);
    }

    private function validatePhone(Request $request, array $rules): array
    {
        $request->merge(['phone' => MemberPhone::normalize($request->input('phone'))]);

        return $request->validate(['phone' => ['required', MemberPhone::RULE], ...$rules]);
    }

    private function validatePasswordBytes(string $password): void
    {
        if (strlen($password) > 72 || str_contains($password, "\0")) {
            throw ValidationException::withMessages(['password' => '密码最多 72 字节，且不能包含空字符。']);
        }
    }

    private function memberData(Member $member): array
    {
        return [
            'id' => $member->id,
            'phone' => $member->phone,
            'phone_verified_at' => $member->phone_verified_at?->toISOString(),
            'last_login_at' => $member->last_login_at?->toISOString(),
        ];
    }
}
