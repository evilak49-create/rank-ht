<?php

namespace App\RedPackets;

use App\Models\Member;
use App\Models\PixTask;
use App\Models\PixWithdrawal;
use App\Models\RedPacketRound;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PixWithdrawals
{
    public function __construct(
        private readonly RedPackets $packets,
        private readonly RedPacketLedger $ledger,
        private readonly PixChannel $channel,
    ) {}

    public function submit(Member $member, string $request): PixWithdrawal
    {
        return DB::transaction(function () use ($member, $request): PixWithdrawal {
            $owner = $this->packets->lockActive($member);
            $request = strtolower($request);
            $existing = PixWithdrawal::query()->whereBelongsTo($owner)->where('request_id', $request)->first();
            if ($existing !== null) {
                return $existing;
            }

            abort_unless($this->channel->isAvailable(), 503, 'Pix 出款暂未开放。');
            abort_if($owner->pix_cpf === null, 422, '请先设置 Pix CPF。');
            $round = RedPacketRound::query()->whereBelongsTo($owner)->whereNull('closed_at')->lockForUpdate()->first();
            abort_if($round === null || $round->balance_cents < $round->threshold_cents || $round->frozen_cents !== 0, 409, '当前红包余额不可提现。');

            $order = PixWithdrawal::create([
                'order_number' => (string) Str::uuid(), 'request_id' => $request, 'member_id' => $owner->id,
                'red_packet_round_id' => $round->id, 'amount_cents' => $round->balance_cents,
                'cpf_snapshot' => $owner->pix_cpf, 'status' => 'pending',
            ]);
            $round->forceFill(['frozen_cents' => $order->amount_cents])->save();
            $this->ledger->record($round, 'freeze', $order->amount_cents, withdrawalId: $order->id);
            $this->event($order, 'submitted');

            return $order;
        }, 3);
    }

    public function approve(PixWithdrawal $order, User $operator): void
    {
        abort_unless($operator->hasPermission('pix.review'), 403);
        $this->withLockedOrder($order, function (PixWithdrawal $current, RedPacketRound $round, Member $member) use ($operator): void {
            if ($current->status !== 'pending') {
                return;
            }
            abort_if($member->disabled_at !== null, 409, '会员账号已禁用。');
            abort_unless($this->channel->isAvailable(), 503, 'Pix 出款暂未开放。');

            $current->forceFill(['status' => 'processing', 'reviewer_id' => $operator->id, 'reviewed_at' => now()])->save();
            $current->task()->create(['next_run_at' => now()]);
            $this->event($current, 'approved', $operator);
        });
    }

    public function reject(PixWithdrawal $order, User $operator, string $reason): void
    {
        abort_unless($operator->hasPermission('pix.review'), 403);
        $this->withLockedOrder($order, function (PixWithdrawal $current, RedPacketRound $round, Member $member, ?PixTask $task) use ($operator, $reason): void {
            if ($current->status !== 'pending') {
                return;
            }

            abort_if($member->disabled_at !== null, 409, '会员账号已禁用。');
            $current->forceFill(['reviewer_id' => $operator->id, 'reviewed_at' => now(), 'review_reason' => $reason]);
            $this->finish($current, $round, $task, 'rejected');
            $this->event($current, 'rejected', $operator);
        });
    }

    public function resume(PixWithdrawal $order, User $operator): void
    {
        abort_unless($operator->hasPermission('pix.resume'), 403);
        $this->withLockedOrder($order, function (PixWithdrawal $current, RedPacketRound $round, Member $member, ?PixTask $task) use ($operator): void {
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
        $this->withLockedOrder($order, function (PixWithdrawal $current, RedPacketRound $round, Member $member, ?PixTask $task) use ($operator): void {
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
            $round = RedPacketRound::query()->lockForUpdate()->findOrFail($order->red_packet_round_id);
            $current = PixWithdrawal::query()->lockForUpdate()->findOrFail($order->id);

            return $operation($current, $round, $member, $current->task()->lockForUpdate()->first());
        }, 3);
    }

    public function finish(PixWithdrawal $order, RedPacketRound $round, ?PixTask $task, string $status, ?string $reference = null): void
    {
        $paid = $status === 'paid';
        $round->forceFill([
            'balance_cents' => $round->balance_cents - ($paid ? $order->amount_cents : 0),
            'frozen_cents' => 0, 'closed_at' => $paid ? now() : null,
        ])->save();
        $order->forceFill(['status' => $status, 'paid_at' => $paid ? now() : null, 'channel_reference' => $reference])->save();
        $task?->forceFill(['next_run_at' => null, 'lease_key' => null, 'lease_until' => null])->save();
        $this->ledger->record($round, $paid ? 'paid' : 'released', $order->amount_cents, withdrawalId: $order->id);
    }

    public function event(PixWithdrawal $order, string $event, ?User $actor = null): void
    {
        DB::table('pix_events')->insert([
            'pix_withdrawal_id' => $order->id, 'user_id' => $actor?->id, 'event' => $event, 'created_at' => now(),
        ]);
    }
}
