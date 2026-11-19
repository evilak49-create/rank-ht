<?php

namespace App\Jobs;

use App\RedPackets\PixDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverPixWithdrawal implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $taskId) {}

    public function handle(PixDelivery $delivery): void
    {
        $delivery->process($this->taskId);
    }
}
