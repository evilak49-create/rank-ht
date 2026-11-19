<?php

namespace App\Console\Commands;

use App\Jobs\DeliverPlatformFunds;
use App\Models\PlatformFundTask;
use Illuminate\Console\Command;

class DispatchPlatformFunds extends Command
{
    protected $signature = 'funds:dispatch';

    protected $description = 'Queue platform-fund delivery and lookup tasks that are due';

    public function handle(): int
    {
        $now = now();
        PlatformFundTask::query()->where('next_run_at', '<=', $now)
            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', $now))
            ->select('id')->eachById(fn (PlatformFundTask $task) => DeliverPlatformFunds::dispatch($task->id));

        return self::SUCCESS;
    }
}
