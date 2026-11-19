<?php

namespace App\Auth;

use Illuminate\Validation\ValidationException;

class MemberPassword
{
    public const RULE = 'required|string|min:8|max:72';

    public static function validateBytes(string $password): void
    {
        if (strlen($password) > 72 || str_contains($password, "\0")) {
            throw ValidationException::withMessages(['password' => '密码最多 72 字节，且不能包含空字符。']);
        }
    }
}
