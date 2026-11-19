<?php

namespace App\Platforms;

use Carbon\CarbonInterface;

final readonly class PlatformAccount
{
    public function __construct(
        public string $id,
        public int $depositCents,
        public int $validBetCents,
        public CarbonInterface $observedAt,
    ) {}
}
