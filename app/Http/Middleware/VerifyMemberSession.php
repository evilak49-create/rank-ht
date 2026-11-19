<?php

namespace App\Http\Middleware;

use App\Models\Member;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class VerifyMemberSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $member = $request->user();
        abort_unless($member instanceof Member, 401);
        $member->refresh();

        if ($member->disabled_at !== null || $request->session()->get('member_session_version') !== $member->session_version) {
            Auth::guard('member')->logout();
            $request->session()->forget('member_session_version');
            $request->session()->regenerate(true);

            return $member->disabled_at !== null
                ? response()->json(['code' => 'member_disabled', 'message' => '会员账号已禁用。'], 403)
                : response()->json(['message' => '请重新登录。'], 401);
        }

        return $next($request);
    }
}
