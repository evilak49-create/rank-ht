<?php

namespace App\Console\Commands;

use App\Jobs\GeneratePlatformReviewBatch;
use App\Models\PlatformReviewBatch;
use Illuminate\Console\Command;

class DispatchPlatformReviewBatches extends Command
{
    protected $signature = 'platform-reviews:dispatch';

    protected $description = 'Queue AI platform description batches that are due';

    public function handle(): int
    {
        PlatformReviewBatch::query()->due()->select('id')
            ->eachById(fn (PlatformReviewBatch $batch) => GeneratePlatformReviewBatch::dispatch($batch->id));

        return self::SUCCESS;
    }
}
