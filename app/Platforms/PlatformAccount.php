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
        public ?PlatformNetProfit $netProfit = null,
    ) {}

    public function rescueSnapshot(): ?array
    {
        if ($this->netProfit === null) {
            return null;
        }

        return [
            'deposit_cents' => $this->depositCents, 'valid_bet_cents' => $this->validBetCents,
            'net_profit_cents' => $this->netProfit->cents, 'source' => $this->netProfit->source,
            'observed_at' => $this->observedAt->toISOString(),
        ];
    }
}
