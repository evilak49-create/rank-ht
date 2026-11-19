<?php

namespace App\Auth;

use Closure;

class AdminCredentials
{
    public const USERNAME_RULES = ['required', 'string', 'max:64', 'regex:/\A[a-zA-Z0-9_.-]+\z/'];

    public static function passwordRules(): array
    {
        return ['required', 'string', 'min:6', function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                $fail('后台密码最多 72 字节，且不能包含空字符。');
            }
        }];
    }
}
