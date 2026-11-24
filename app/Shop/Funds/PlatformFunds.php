<?php

namespace App\Shop\Funds;

use App\Models\FundRateVersion;
use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Models\PlatformFundOrder;
use App\Models\PlatformFundTask;
use App\Models\User;
use App\Points\CashBalance;
use App\Points\PointWallet;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PlatformFunds
{
    public const PERMISSIONS = ['funds.view', 'funds.review', 'funds.resume'];

    public function __construct(
        private readonly PointWallet $wallet,
        private readonly FundChannel $channel,
        private readonly CashBalance $cash,
    ) {}

    public function rate(): array
    {
        return $this->rateData($this->currentRate());
    }

    public function preview(Member $member, array $data): array
    {
        $binding = $this->binding($member, $data['binding_id']);
        $this->requireAvailable($binding->platform, $member, requireChannel: ! isset($data['amount_cents']));

        if (isset($data['amount_cents'])) {
            $amount = (int) $data['amount_cents'];
            abort_if($amount > $member->available_balance_cents, 409, '可用余额不足。');

            return ['amount_cents' => $amount, 'balance_paid_cents' => $amount, 'currency' => 'BRL'];
        }

        return $this->conversion($this->currentRate(), (int) $data['points']);
    }

    public function checkout(Member $member, array $data): PlatformFundOrder
    {
        $binding = $this->binding($member, $data['binding_id']);

        return DB::transaction(function () use ($member, $binding, $data): PlatformFundOrder {
            $platform = Platform::query()->lockForUpdate()->findOrFail($binding->platform_id);
            $owner = Member::query()->lockForUpdate()->findOrFail($member->id);
            abort_if($owner->disabled_at !== null, 403, '会员账号已禁用。');
            abort_if($owner->session_version !== $member->session_version, 401, '会员登录会话已失效。');

            $request = strtolower($data['request_id']);
            $cashAmount = isset($data['amount_cents']) ? (int) $data['amount_cents'] : null;
            $existing = PlatformFundOrder::query()->where('member_id', $owner->id)->where('request_id', $request)->first();
            if ($existing !== null) {
                abort_if($existing->platform_binding_id !== $binding->id || $existing->balance_paid_cents !== $cashAmount
                    || ($cashAmount === null && $existing->points !== (int) $data['points']), 409, '该订单请求号已使用。');

                return $existing;
            }

            $this->requireAvailable($platform, $owner, requireChannel: $cashAmount === null);
            $conversion = $cashAmount === null
                ? $this->confirmPointConversion((int) $data['points'], (int) $data['rate_version'])
                : ['amount_cents' => $cashAmount];

            $order = PlatformFundOrder::create([
                'member_id' => $owner->id, 'platform_id' => $platform->id,
                'platform_binding_id' => $binding->id, 'platform_account_id' => $binding->platform_account_id,
                'request_id' => $request, 'order_number' => (string) Str::uuid(), 'fund_rate_version_id' => $conversion['rate_version'] ?? null,
                'points' => $conversion['points'] ?? 0, 'cents_per_point' => $conversion['cents_per_point'] ?? 0,
                'balance_paid_cents' => $cashAmount,
                'amount_cents' => $conversion['amount_cents'], 'status' => 'pending',
            ]);
            if ($cashAmount !== null) {
                $this->cash->charge($owner, $cashAmount, 'platform_payment', $order->id);
            } else {
                $this->wallet->record($owner, -$order->points, [
                    'operation_key' => (string) Str::uuid(), 'reason' => '平台资金兑换',
                    'source' => 'platform_funds', 'source_id' => $order->id,
                ]);
            }
            $this->event($order, 'submitted');

            return $order;
        }, 3);
    }

    public function approve(PlatformFundOrder $order, User $operator): void
    {
        abort_unless($operator->hasPermission('funds.review'), 403);

        $this->withLockedOrder($order, function (Platform $platform, Member $member, PlatformFundOrder $current, ?PlatformFundTask $task) use ($operator): void {
            $manual = $current->balance_paid_cents !== null;
            if ($task !== null || ($manual && $current->status === 'paid')) {
                return;
            }
            abort_unless($current->status === 'pending', 409, '只有待审核订单可以审核。');
            $this->requireAvailable($platform, $member, requireChannel: ! $manual);
            $reviewedAt = now();
            $current->forceFill([
                'status' => $manual ? 'paid' : 'processing',
                'reviewer_id' => $operator->id,
                'reviewed_at' => $reviewedAt,
                'paid_at' => $manual ? $reviewedAt : null,
            ])->save();
            $this->event($current, 'approved', $operator);
            if ($manual) {
                $this->event($current, 'manual_paid', $operator);

                return;
            }

            $current->task()->create(['next_run_at' => $reviewedAt]);
        });
    }

    public function reject(PlatformFundOrder $order, string $reason, User $operator): void
    {
        abort_unless($operator->hasPermission('funds.review'), 403);
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => 'required|string|max:300'])->validate();

        $this->withLockedOrder($order, function (Platform $platform, Member $member, PlatformFundOrder $current) use ($operator, $reason): void {
            if ($current->status === 'rejected') {
                return;
            }
            abort_unless($current->status === 'pending', 409, '只有待审核订单可以驳回。');
            $this->requireAvailable($platform, $member, requireChannel: false);
            $deducted = $current->balance_paid_cents !== null;
            if (! $deducted) {
                $this->refund($member, $current, $reason, $operator);
            }
            $current->forceFill([
                'status' => 'rejected', 'reviewer_id' => $operator->id, 'reviewed_at' => now(), 'review_reason' => $reason,
                'rejection_deducted' => $deducted,
            ])->save();
            $this->event($current, $deducted ? 'rejected_deducted' : 'rejected', $operator);
        });
    }

    public function cancel(PlatformFundOrder $order, User $operator): void
    {
        abort_unless($operator->hasPermission('funds.review'), 403);
        $this->withLockedOrder($order, function (Platform $platform, Member $member, PlatformFundOrder $current) use ($operator): void {
            if ($current->status === 'cancelled') {
                return;
            }

            abort_unless($current->status === 'pending', 409, '只有待审核订单可以取消。');
            $this->refund($member, $current, '取消平台资金兑换', $operator);
            $current->forceFill([
                'status' => 'cancelled', 'reviewer_id' => $operator->id, 'reviewed_at' => now(),
            ])->save();
            $this->event($current, 'cancelled', $operator);
        });
    }

    public function resume(PlatformFundOrder $order, User $operator): void
    {
        abort_unless($operator->hasPermission('funds.resume'), 403);
        $this->withLockedOrder($order, function (Platform $platform, Member $owner, PlatformFundOrder $current, ?PlatformFundTask $task) use ($operator): void {
            $this->requireAvailable($platform, $owner);
            abort_unless($current->status === 'processing' && $task !== null && $task->paused_at !== null && $task->send_started_at === null, 409, '该订单没有可恢复的待发放任务。');
            $task->forceFill(['paused_at' => null, 'pause_reason' => null, 'next_run_at' => now()])->save();
            $this->event($current, 'resumed', $operator);
        });
    }

    public function requestLookup(PlatformFundOrder $order, User $operator): void
    {
        abort_unless($operator->hasPermission('funds.resume'), 403);
        $this->withLockedOrder($order, function (Platform $platform, Member $member, PlatformFundOrder $current, ?PlatformFundTask $task) use ($operator): void {
            abort_unless(in_array($current->status, ['processing', 'unresolved'], true) && $task?->send_started_at !== null, 409, '该订单尚未发送或已结单。');
            abort_if($task->lease_until?->isFuture(), 409, '任务正在处理，请稍后重试。');
            $task->forceFill(['next_run_at' => now(), 'needs_review_at' => null])->save();
            $this->event($current, 'lookup_requested', $operator);
        });
    }

    public function withLockedOrder(PlatformFundOrder $order, Closure $work): mixed
    {
        return DB::transaction(function () use ($order, $work): mixed {
            $platform = Platform::query()->lockForUpdate()->findOrFail($order->platform_id);
            $owner = Member::query()->lockForUpdate()->findOrFail($order->member_id);
            $current = PlatformFundOrder::query()->lockForUpdate()->findOrFail($order->id);
            $task = $current->task()->lockForUpdate()->first();

            return $work($platform, $owner, $current, $task);
        }, 3);
    }

    public function refund(Member $lockedMember, PlatformFundOrder $order, string $reason, ?User $operator = null): void
    {
        if ($order->balance_paid_cents !== null) {
            $this->cash->refund($lockedMember, $order->balance_paid_cents, 'platform_refund', $order->id);

            return;
        }

        $this->wallet->record($lockedMember, $order->points, [
            'operation_key' => $order->order_number, 'reason' => $reason, 'user_id' => $operator?->id,
            'source' => 'platform_refund', 'source_id' => $order->id,
        ]);
    }

    public function event(PlatformFundOrder $order, string $event, ?User $operator = null): void
    {
        DB::table('platform_fund_events')->insert([
            'platform_fund_order_id' => $order->id, 'user_id' => $operator?->id, 'event' => $event, 'created_at' => now(),
        ]);
    }

    private function binding(Member $member, string|int $id): PlatformBinding
    {
        return PlatformBinding::query()->where('member_id', $member->id)->with('platform')->findOrFail($id);
    }

    private function requireAvailable(Platform $platform, Member $member, bool $requireChannel = true): void
    {
        abort_if($member->disabled_at !== null, 403, '会员账号已禁用。');
        abort_unless($platform->status() === 'available', 409, '平台暂不可用，订单暂停处理。');
        abort_if($requireChannel && ! $this->channel->isAvailable($platform), 503, '该平台暂未开放资金发放。');
    }

    private function confirmPointConversion(int $points, int $version): array
    {
        FundRateVersion::query()->oldest('id')->lockForUpdate()->firstOrFail();
        $rate = FundRateVersion::query()->latest('id')->lockForUpdate()->firstOrFail();
        $conversion = $this->conversion($rate, $points);
        if ($rate->id !== $version) {
            throw new HttpResponseException(response()->json([
                'code' => 'rate_changed', 'message' => '兑换比例已变更，请确认新金额。', 'data' => $conversion,
            ], 409));
        }

        return $conversion;
    }

    private function conversion(FundRateVersion $rate, int $points): array
    {
        return [
            ...$this->rateData($rate), 'points' => $points, 'amount_cents' => $points * $rate->cents_per_point,
        ];
    }

    private function currentRate(): FundRateVersion
    {
        return FundRateVersion::query()->latest('id')->firstOrFail();
    }

    private function rateData(FundRateVersion $rate): array
    {
        return ['rate_version' => $rate->id, 'cents_per_point' => $rate->cents_per_point, 'currency' => 'BRL'];
    }
}
