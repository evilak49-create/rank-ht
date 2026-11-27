<?php

namespace App\Jobs;

use App\Models\PlatformBinding;
use App\Platforms\PlatformRecharges;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SyncPromotionRecharges implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 21600;

    public function __construct(public readonly int $bindingId, public readonly int $page = 1) {}

    public function uniqueId(): string
    {
        return $this->bindingId.':'.$this->page;
    }

    public function backoff(): array
    {
        return [120, 600];
    }

    public function handle(PlatformRecharges $recharges): void
    {
        $binding = PlatformBinding::query()->with('member')
            ->whereHas('member', fn ($query) => $query->whereNotNull('promotion_channel_id'))
            ->find($this->bindingId);
        if ($binding === null) {
            return;
        }

        try {
            $result = $recharges->list($binding->member, (string) $binding->id, $this->page);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 404) {
                return;
            }

            throw $exception;
        }

        if ($result['has_more']) {
            self::dispatch($binding->id, $this->page + 1)->delay(now()->addSeconds(10));
        }
    }
}
