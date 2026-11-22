<?php

namespace App\Platforms;

use Carbon\CarbonImmutable;
use Closure;

final readonly class PlatformRechargePage
{
    public const SIZE = 50;

    public function __construct(public array $records, public int $total) {}

    public static function fromProvider(mixed $rows, mixed $total, Closure $normalize): self
    {
        $count = is_int($total) || is_string($total)
            ? filter_var($total, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])
            : false;
        if ($count === false || ! is_array($rows) || ! array_is_list($rows) || count($rows) > self::SIZE || count($rows) > $count) {
            throw new PlatformQueryFailure('schema');
        }

        $records = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new PlatformQueryFailure('schema');
            }
            if (($record = $normalize($row)) !== null) {
                $records[] = $record;
            }
        }

        return new self($records, $count);
    }

    public static function record(mixed $reference, ?int $amount, CarbonImmutable $at): array
    {
        if ((! is_int($reference) && ! is_string($reference)) || ! preg_match('/\A[[:graph:]]{1,128}\z/', (string) $reference)
            || $amount === null || $amount <= 0 || $amount > 9_007_199_254_740_991) {
            throw new PlatformQueryFailure('schema');
        }

        return ['external_id' => (string) $reference, 'amount_cents' => $amount, 'recharged_at' => $at->utc()];
    }
}
