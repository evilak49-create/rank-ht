<?php

namespace App\Points;

class PointRules
{
    public const SCALE = 1_000_000;

    public static function display(int $units, int $places = 6): string
    {
        $scale = 10 ** $places;

        return intdiv($units, $scale).'.'.str_pad((string) ($units % $scale), $places, '0', STR_PAD_LEFT);
    }

    public static function toUnits(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

        return (int) $whole * self::SCALE + (int) str_pad($fraction, 6, '0');
    }
}
