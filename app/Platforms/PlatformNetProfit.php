<?php

namespace App\Platforms;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use SensitiveParameter;

final readonly class PlatformNetProfit
{
    private const FIELD_RULE = '/\A[A-Za-z_][A-Za-z0-9_]{0,79}\z/';

    public const RULES = [
        'net_profit_field' => ['nullable', 'required_with:net_profit_unit,net_profit_direction', 'string', 'regex:'.self::FIELD_RULE],
        'net_profit_unit' => ['nullable', 'required_with:net_profit_field,net_profit_direction', 'in:brl,cents'],
        'net_profit_direction' => ['nullable', 'required_with:net_profit_field,net_profit_unit', 'in:profit_positive,loss_positive'],
    ];

    private function __construct(public int $cents, public string $source) {}

    public static function fromAccountRow(#[SensitiveParameter] array $row, #[SensitiveParameter] array $config, array $accountSource): ?self
    {
        $field = $config['net_profit_field'] ?? null;
        $unit = $config['net_profit_unit'] ?? null;
        $direction = $config['net_profit_direction'] ?? null;
        if (! is_string($field) || ! preg_match(self::FIELD_RULE, $field)
            || ! in_array($unit, ['brl', 'cents'], true) || ! in_array($direction, ['profit_positive', 'loss_positive'], true)) {
            return null;
        }

        $amount = $row[$field] ?? null;
        if (is_float($amount) && is_finite($amount) && abs($amount) < 1_000_000_000_000) {
            $amount = json_encode($amount, JSON_THROW_ON_ERROR);
        }
        if (! is_int($amount) && (! is_string($amount) || ! preg_match('/\A-?[0-9]{1,24}(?:\.[0-9]{1,8})?\z/', $amount))) {
            return null;
        }

        try {
            $cents = BigDecimal::of($amount)->multipliedBy($unit === 'brl' ? 100 : 1)
                ->multipliedBy($direction === 'loss_positive' ? -1 : 1)
                ->toScale(0, RoundingMode::Unnecessary)->toInt();
        } catch (MathException) {
            return null;
        }

        $source = hash('sha256', json_encode([...$accountSource, $field, $unit, $direction], JSON_THROW_ON_ERROR));

        return new self($cents, $source);
    }
}
