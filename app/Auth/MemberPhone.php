<?php

namespace App\Auth;

class MemberPhone
{
    public static function mask(?string $phone): ?string
    {
        if ($phone === null || ! preg_match('/\A[0-9]{11}\z/', $phone)) {
            return null;
        }

        return substr($phone, 0, 3).'****'.substr($phone, -4);
    }

    public static function normalize(mixed $phone): string
    {
        if (! is_string($phone) || strlen($phone) > 32 || ! preg_match('/\A(?:\+55)?[0-9 ()-]+\z/', $phone)) {
            return '';
        }

        $national = str_starts_with($phone, '+55') ? substr($phone, 3) : $phone;

        return preg_replace('/[^0-9]/', '', $national);
    }

    public const RULE = 'regex:/\A[1-9][0-9]9[0-9]{8}\z/';
}
