<?php

namespace App\Shop\Funds;

use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformFundOrder;
use App\Models\PlatformFundTask;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class FundDelivery
{
    public function __construct(private readonly PlatformFunds $funds, private readonly FundChannel $channel) {}

    public function process(int $taskId): void
    {
        $order = PlatformFundTask::query()->with('order')->find($taskId)?->order;
        if ($order === null) {
            return;
        }

        $claim = $this->funds->withLockedOrder($order, function (Platform $platform, Member $member, PlatformFundOrder $current, ?PlatformFundTask $task): ?array {
            if ($task === null || $task->next_run_at === null || $task->next_run_at->isFuture() || $task->lease_until?->isFuture()
                || ! in_array($current->status, ['processing', 'unresolved'], true)) {
                return null;
            }

            $send = $task->send_started_at === null;
            if ($send) {
                $pause = match (true) {
                    $member->disabled_at !== null => 'member_disabled',
                    $platform->status() !== 'available' => 'platform_unavailable',
                    ! $this->channel->isAvailable($platform) => 'channel_unavailable',
                    default => null,
                };
                if ($pause !== null || $task->paused_at !== null) {
                    $task->forceFill(['paused_at' => $task->paused_at ?? now(), 'pause_reason' => $task->pause_reason ?? $pause, 'next_run_at' => null])->save();

                    return null;
                }
                $task->forceFill(['send_started_at' => now()]);
            }

            $lease = (string) Str::uuid();
            $task->forceFill(['lease_key' => $lease, 'lease_until' => now()->addMinutes(5)])->save();
            $this->funds->event($current, $send ? 'send_started' : 'lookup_started');

            return [$send, $lease, $current];
        });

        if ($claim === null) {
            return;
        }
        [$send, $lease, $current] = $claim;
        try {
            $result = $send ? $this->channel->send($current) : $this->channel->lookup($current);
        } catch (Throwable $exception) {
            Log::warning('Platform funds channel response unavailable.', ['order_id' => $current->id, 'exception_type' => $exception::class]);
            $result = new FundResult('unknown');
        }

        $this->finish($current, $lease, $result);
    }

    private function finish(PlatformFundOrder $order, string $lease, FundResult $result): void
    {
        $this->funds->withLockedOrder($order, function (Platform $platform, Member $member, PlatformFundOrder $current, ?PlatformFundTask $task) use ($lease, $result): void {
            if ($task === null || $task->lease_key !== $lease || ! in_array($current->status, ['processing', 'unresolved'], true)) {
                return;
            }

            $task->forceFill(['lease_key' => null, 'lease_until' => null, 'next_run_at' => null]);
            if ($result->status === 'paid' && filled($result->reference) && strlen($result->reference) <= 190) {
                $current->forceFill(['status' => 'paid', 'paid_at' => now(), 'channel_reference' => $result->reference]);
                $task->forceFill(['needs_review_at' => null]);
            } elseif ($result->status === 'failed') {
                $this->funds->refund($member, $current, '渠道确认资金未到账');
                $current->forceFill(['status' => 'failed']);
                $task->forceFill(['needs_review_at' => null]);
            } else {
                $current->forceFill(['status' => 'unresolved']);
                if ($task->send_started_at->lte(now()->subDay())) {
                    $task->forceFill(['needs_review_at' => now()]);
                } else {
                    $task->forceFill(['next_run_at' => now()->addMinutes(5)]);
                }
            }
            $current->save();
            $task->save();
            $this->funds->event($current, $current->status);
        });
    }
}
