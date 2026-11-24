<?php

namespace App\Jobs;

use App\Points\PointSettlement;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchDailyCashback implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $uniqueFor = 86400;

    public function __construct(public readonly int $bindingId, public readonly string $day) {}

    public function uniqueId(): string
    {
        return $this->bindingId.':'.$this->day;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(PointSettlement $settlement): void
    {
        $settlement->dispatchDaily($this->bindingId, $this->day);
    }
}
