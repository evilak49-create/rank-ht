<?php

namespace App\Console\Commands;

use App\Jobs\DeliverPixWithdrawal;
use App\Models\PixTask;
use App\RedPackets\PixChannel;
use Illuminate\Console\Command;

class DispatchPixWithdrawals extends Command
{
    protected $signature = 'pix:dispatch';

    protected $description = 'Queue Pix delivery and lookup tasks that are due';

    public function handle(PixChannel $channel): int
    {
        $now = now();
        PixTask::query()->where('next_run_at', '<=', $now)
            ->when(! $channel->isAvailable(), fn ($query) => $query->whereHas('withdrawal', fn ($order) => $order->whereNotNull('payout_configuration')))
            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', $now))
            ->select('id')->eachById(fn (PixTask $task) => DeliverPixWithdrawal::dispatch($task->id));

        return self::SUCCESS;
    }
}
