<?php

namespace App\Jobs;

use App\Platforms\PlatformReviewBatches;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GeneratePlatformReviewBatch implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $batchId) {}

    public function uniqueId(): string
    {
        return (string) $this->batchId;
    }

    public function handle(PlatformReviewBatches $batches): void
    {
        $batches->process($this->batchId);
    }
}
