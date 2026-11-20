<?php

namespace App\RedPackets;

use App\Models\Member;
use App\Models\PixPayoutChannel;
use App\Models\PixTask;
use App\Models\PixWithdrawal;
use App\Models\RedPacketRound;
use App\Models\User;
use App\Points\CashBalance;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PixWithdrawals
{
    private const MANUAL_CHANNEL = '人工 Pix 转账';

    private const SIMULATOR_CHANNEL = 'Pix 模拟渠道（测试）';

    public function __construct(
        private readonly RedPackets $packets,
        private readonly RedPacketLedger $ledger,
        private readonly PixChannel $channel,
        private readonly CashBalance $cash,
    ) {}

    public function submit(Member $member, string $request, ?int $amount = null): PixWithdrawal
    {
        return DB::transaction(function () use ($member, $request, $amount): PixWithdrawal {
            $owner = $this->packets->lockActive($member);
            $request = strtolower($request);
            $existing = PixWithdrawal::query()->whereBelongsTo($owner)->where('request_id', $request)->first();
            if ($existing !== null) {
                abort_if(($existing->red_packet_round_id === null) !== ($amount !== null)
                    || ($amount !== null && $existing->amount_cents !== $amount), 409, '提现申请内容已变更。');
                return $existing;
            }

            abort_if($amount === null && ! $this->channel->isAvailable()
                && ! PixPayoutChannel::query()->where('enabled', true)->exists(), 503, 'Pix 出款暂未开放。');
            abort_if($owner->pix_cpf === null, 422, '请先设置 Pix CPF。');
            $round = null;
            if ($amount === null) {
                $round = RedPacketRound::query()->whereBelongsTo($owner)->whereNull('closed_at')->lockForUpdate()->first();
                abort_if($round === null || $round->balance_cents < $round->threshold_cents || $round->frozen_cents !== 0, 409, '当前红包余额不可提现。');
            }

            $order = PixWithdrawal::create([
                'order_number' => (string) Str::uuid(), 'request_id' => $request, 'member_id' => $owner->id,
                'red_packet_round_id' => $round?->id, 'amount_cents' => $amount ?? $round->balance_cents,
                'cpf_snapshot' => $owner->pix_cpf, 'status' => 'pending',
            ]);
            if ($round === null) {
                $this->cash->holdWithdrawal($owner, $order->amount_cents, $order->id);
            } else {
                $round->forceFill(['frozen_cents' => $order->amount_cents])->save();
                $this->ledger->record($round, 'freeze', $order->amount_cents, withdrawalId: $order->id);
            }
            $this->event($order, 'submitted');

            return $order;
        }, 3);
    }

    public function payoutOptions(PixWithdrawal $order): array
    {
        $options = [];
        foreach (PixPayoutChannel::query()->where('enabled', true)->orderBy('id')->pluck('name', 'id') as $id => $name) {
            $options['provider:'.$id] = $name;
        }
        if ($order->red_packet_round_id === null) {
            $options['manual'] = self::MANUAL_CHANNEL;
        }
        if ($this->channel->isAvailable()) {
            $options['simulator'] = self::SIMULATOR_CHANNEL;
        }

        return $options;
    }

    public function approve(PixWithdrawal $order, User $operator, ?string $selection = null): void
    {
        abort_unless($operator->hasPermission('pix.review'), 403);
        $this->withLockedOrder($order, function (PixWithdrawal $current, ?RedPacketRound $round, Member $member) use ($operator, $selection): void {
            if ($current->status !== 'pending') {
                return;
            }
            abort_if($member->disabled_at !== null, 409, '会员账号已禁用。');

            if ($selection === null) {
                $selection = $this->channel->isAvailable() ? 'simulator' : 'manual';
                abort_if($selection === 'manual' && $round !== null, 503, '请选择红包的 Pix 出款渠道。');
            }
            $provider = null;
            if (preg_match('/\Aprovider:([1-9][0-9]{0,18})\z/', $selection, $matches)) {
                $provider = PixPayoutChannel::query()->where('enabled', true)->lockForUpdate()->find($matches[1]);
            }
            $automated = $selection === 'simulator' && $this->channel->isAvailable();
            $channelName = match (true) {
                $provider !== null => $provider->name,
                $automated => self::SIMULATOR_CHANNEL,
                $selection === 'manual' && $round === null => self::MANUAL_CHANNEL,
                default => throw ValidationException::withMessages(['channel' => '请选择可用的出款渠道。']),
            };

            $current->forceFill([
                'status' => 'processing', 'reviewer_id' => $operator->id, 'reviewed_at' => now(),
                'payout_channel_id' => $provider?->id, 'payout_channel_name' => $channelName,
            ])->save();
            if ($automated) {
                $current->task()->create(['next_run_at' => now()]);
            }
            $this->event($current, $automated ? 'approved' : 'manual_approved', $operator);
        });
    }

    public function reject(PixWithdrawal $order, User $operator, string $reason): void
    {
        abort_unless($operator->hasPermission('pix.review'), 403);
        $this->withLockedOrder($order, function (PixWithdrawal $current, ?RedPacketRound $round, Member $member, ?PixTask $task) use ($operator, $reason): void {
            if ($current->status !== 'pending' && ! ($current->status === 'processing' && $current->usesManualPayout($task))) {
                return;
            }

            abort_if($member->disabled_at !== null, 409, '会员账号已禁用。');
            $current->forceFill(['reviewer_id' => $operator->id, 'reviewed_at' => now(), 'review_reason' => $reason]);
            $this->finish($current, $round, $member, $task, 'rejected');
            $this->event($current, 'rejected', $operator);
        });
    }

    public function confirmManualTransfer(PixWithdrawal $order, User $operator, string $reference): void
    {
        abort_unless($operator->hasPermission('pix.review'), 403);
        $reference = strtoupper(trim($reference));
        Validator::make(['reference' => $reference], ['reference' => ['required', 'max:190', 'regex:/\A[A-Z0-9._-]+\z/']])->validate();

        try {
            $this->withLockedOrder($order, function (PixWithdrawal $current, ?RedPacketRound $round, Member $member, ?PixTask $task) use ($operator, $reference): void {
                abort_unless($current->usesManualPayout($task), 409, '该订单不适用人工出款。');
                if ($current->status === 'paid' && $current->manual_transfer_reference === $reference) {
                    return;
                }
                abort_unless($current->status === 'processing', 409, '该订单尚未审核或已结单。');
                $current->forceFill(['manual_transfer_reference' => $reference]);
                $this->finish($current, $round, $member, null, 'paid', $reference);
                $this->event($current, 'paid_manually', $operator);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['reference' => '该出款流水号已登记。']);
        }
    }

    public function resume(PixWithdrawal $order, User $operator): void
    {
        abort_unless($operator->hasPermission('pix.resume'), 403);
        $this->withLockedOrder($order, function (PixWithdrawal $current, ?RedPacketRound $round, Member $member, ?PixTask $task) use ($operator): void {
            abort_unless($current->status === 'processing' && $task?->paused_at !== null && $task->send_started_at === null, 409);
            abort_if($member->disabled_at !== null, 409, '会员账号已禁用。');
            abort_unless($this->channel->isAvailable(), 503, 'Pix 出款暂未开放。');
            $task->forceFill(['paused_at' => null, 'next_run_at' => now()])->save();
            $this->event($current, 'resumed', $operator);
        });
    }

    public function requestLookup(PixWithdrawal $order, User $operator): void
    {
        abort_unless($operator->hasPermission('pix.review'), 403);
        $this->withLockedOrder($order, function (PixWithdrawal $current, ?RedPacketRound $round, Member $member, ?PixTask $task) use ($operator): void {
            abort_unless(in_array($current->status, ['processing', 'unresolved'], true)
                && $task?->send_started_at !== null && ! $task->lease_until?->isFuture(), 409);
            abort_unless($this->channel->isAvailable(), 503, 'Pix 出款暂未开放。');
            $task->forceFill(['next_run_at' => now()])->save();
            $this->event($current, 'lookup_requested', $operator);
        });
    }

    public function withLockedOrder(PixWithdrawal $order, Closure $operation): mixed
    {
        return DB::transaction(function () use ($order, $operation): mixed {
            $member = Member::query()->lockForUpdate()->findOrFail($order->member_id);
            $round = $order->red_packet_round_id === null ? null : RedPacketRound::query()->lockForUpdate()->findOrFail($order->red_packet_round_id);
            $current = PixWithdrawal::query()->lockForUpdate()->findOrFail($order->id);

            return $operation($current, $round, $member, $current->task()->lockForUpdate()->first());
        }, 3);
    }

    public function finish(PixWithdrawal $order, ?RedPacketRound $round, Member $member, ?PixTask $task, string $status, ?string $reference = null): void
    {
        $paid = $status === 'paid';
        if ($round === null) {
            $this->cash->finishWithdrawal($member, $order->amount_cents, $order->id, $paid);
        } else {
            $round->forceFill([
                'balance_cents' => $round->balance_cents - ($paid ? $order->amount_cents : 0),
                'frozen_cents' => 0, 'closed_at' => $paid ? now() : null,
            ])->save();
            $this->ledger->record($round, $paid ? 'paid' : 'released', $order->amount_cents, withdrawalId: $order->id);
        }
        $order->forceFill(['status' => $status, 'paid_at' => $paid ? now() : null, 'channel_reference' => $reference])->save();
        $task?->forceFill(['next_run_at' => null, 'lease_key' => null, 'lease_until' => null])->save();
    }

    public function event(PixWithdrawal $order, string $event, ?User $actor = null): void
    {
        DB::table('pix_events')->insert([
            'pix_withdrawal_id' => $order->id, 'user_id' => $actor?->id, 'event' => $event, 'created_at' => now(),
        ]);
    }
}
