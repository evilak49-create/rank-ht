<?php

namespace App\RedPackets;

use App\Models\PixPayoutChannel;
use Illuminate\Http\Client\ConnectionException;

class PixChannelBalances
{
    public function __construct(private readonly ExternalPixChannel $external) {}

    public function lookup(PixPayoutChannel $channel): ?int
    {
        $configuration = $channel->configuration;
        if (! isset(ExternalPixChannel::PROVIDERS[$channel->driver]) || ($configuration['driver'] ?? null) !== $channel->driver) {
            return null;
        }

        try {
            return $this->external->balance($configuration);
        } catch (ConnectionException) {
            return null;
        }
    }

    public function refresh(PixPayoutChannel $channel): ?int
    {
        $attemptedAt = now();
        $amount = $this->lookup($channel);
        $changes = ['last_balance_attempt_at' => $attemptedAt, 'last_balance_failed' => $amount === null];
        if ($amount !== null) {
            $changes['last_balance_cents'] = $amount;
            $changes['last_balance_at'] = now();
        }

        PixPayoutChannel::query()->whereKey($channel->id)
            ->where('configuration', $channel->getRawOriginal('configuration'))
            ->where(fn ($query) => $query->whereNull('last_balance_attempt_at')->orWhere('last_balance_attempt_at', '<=', $attemptedAt))
            ->update($changes);

        return $amount;
    }
}
