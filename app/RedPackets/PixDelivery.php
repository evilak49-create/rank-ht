<?php

namespace App\RedPackets;

use App\Models\Member;
use App\Models\PixTask;
use App\Models\PixWithdrawal;
use App\Models\RedPacketRound;
use Illuminate\Support\Str;
use Throwable;

class PixDelivery
{
    public function __construct(private readonly PixWithdrawals $withdrawals, private readonly PixChannel $channel) {}

    public function process(int $id): void
    {
        $order = PixTask::query()->find($id)?->withdrawal;
        if ($order === null || ! $this->channel->isAvailable()) {
            return;
        }

        $claim = $this->withdrawals->withLockedOrder($order, function (PixWithdrawal $current, ?RedPacketRound $round, Member $member, ?PixTask $task): ?array {
            if (! in_array($current->status, ['processing', 'unresolved'], true) || $task?->paused_at !== null
                || $task?->next_run_at === null || $task->next_run_at->isFuture() || $task->lease_until?->isFuture()) {
                return null;
            }

            $send = $task->send_started_at === null;
            if ($send && $member->disabled_at !== null) {
                $task->forceFill(['paused_at' => now(), 'next_run_at' => null])->save();
                $this->withdrawals->event($current, 'paused');

                return null;
            }

            $key = (string) Str::uuid();
            $task->forceFill([
                'lease_key' => $key, 'lease_until' => now()->addMinutes(5),
                'send_started_at' => $task->send_started_at ?? now(),
            ])->save();
            $this->withdrawals->event($current, $send ? 'send_started' : 'lookup_started');

            return [$current, $key, $send];
        });
        if ($claim === null) {
            return;
        }

        [$current, $key, $send] = $claim;
        try {
            $result = $send ? $this->channel->send($current) : $this->channel->lookup($current);
        } catch (Throwable) {
            $result = new PixResult('unknown');
        }

        $this->withdrawals->withLockedOrder($current, function (PixWithdrawal $order, ?RedPacketRound $round, Member $member, ?PixTask $task) use ($key, $result): void {
            if ($task?->lease_key === $key) {
                $this->applyResult($order, $round, $member, $task, $result);
            }
        });
    }

    public function reconcile(PixWithdrawal $order, PixResult $result): void
    {
        $this->withdrawals->withLockedOrder($order, function (PixWithdrawal $current, ?RedPacketRound $round, Member $member, ?PixTask $task) use ($result): void {
            if (in_array($current->status, ['processing', 'unresolved'], true) && $task?->send_started_at !== null) {
                $this->applyResult($current, $round, $member, $task, $result);
            }
        });
    }

    private function applyResult(PixWithdrawal $order, ?RedPacketRound $round, Member $member, PixTask $task, PixResult $result): void
    {
        $reference = $result->reference;
        if ($result->status === 'failed' || ($result->status === 'paid' && filled($reference) && strlen($reference) <= 190)) {
            $this->withdrawals->finish($order, $round, $member, $task, $result->status, $result->status === 'paid' ? $reference : null);
            $this->withdrawals->event($order, $result->status);

            return;
        }

        $manualReview = $task->send_started_at->lte(now()->subDay());
        $order->forceFill(['status' => 'unresolved'])->save();
        $task->forceFill([
            'next_run_at' => $manualReview ? null : now()->addMinutes(5),
            'needs_review_at' => $manualReview ? ($task->needs_review_at ?? now()) : null,
            'lease_key' => null, 'lease_until' => null,
        ])->save();
        $this->withdrawals->event($order, $manualReview ? 'needs_review' : 'result_unknown');
    }
}
