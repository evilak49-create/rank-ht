<?php

namespace App\RedPackets;

use Illuminate\Validation\ValidationException;

class Cpf
{
    public static function normalize(string $value): string
    {
        $digits = preg_match('/\A(?:[0-9]{11}|[0-9]{3}\.[0-9]{3}\.[0-9]{3}-[0-9]{2})\z/', $value)
            ? str_replace(['.', '-'], '', $value) : '';
        if ($digits === '' || preg_match('/\A([0-9])\1{10}\z/', $digits)) {
            self::invalid();
        }

        foreach ([9, 10] as $length) {
            $sum = 0;
            for ($index = 0; $index < $length; $index++) {
                $sum += (int) $digits[$index] * ($length + 1 - $index);
            }
            if (((($sum * 10) % 11) % 10) !== (int) $digits[$length]) {
                self::invalid();
            }
        }

        return $digits;
    }

    public static function mask(?string $cpf): ?string
    {
        return $cpf === null ? null : '***.***.***-'.substr($cpf, -2);
    }

    private static function invalid(): never
    {
        throw ValidationException::withMessages(['cpf' => '请输入有效的巴西 CPF。']);
    }
}
