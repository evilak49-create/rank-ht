<?php

namespace App\Jobs;

use App\Shop\Funds\FundDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverPlatformFunds implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $taskId) {}

    public function handle(FundDelivery $delivery): void
    {
        $delivery->process($this->taskId);
    }
}
