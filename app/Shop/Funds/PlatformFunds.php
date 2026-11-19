<?php

namespace App\Shop\Funds;

use App\Models\FundRateVersion;
use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Models\PlatformFundOrder;
use App\Models\PlatformFundTask;
use App\Models\User;
use App\Points\PointWallet;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PlatformFunds
{
    public const PERMISSIONS = ['funds.view', 'funds.review', 'funds.resume'];

    public function __construct(private readonly PointWallet $wallet, private readonly FundChannel $channel) {}

    public function preview(Member $member, array $data): array
    {
        $binding = $this->binding($member, $data['binding_id']);
        $this->requireAvailable($binding->platform, $member);

        return $this->conversion(FundRateVersion::query()->latest('id')->firstOrFail(), (int) $data['points']);
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
            $existing = PlatformFundOrder::query()->where('member_id', $owner->id)->where('request_id', $request)->first();
            if ($existing !== null) {
                abort_if($existing->platform_binding_id !== $binding->id || $existing->points !== (int) $data['points'], 409, '该订单请求号已使用。');

                return $existing;
            }

            $this->requireAvailable($platform, $owner);
            FundRateVersion::query()->oldest('id')->lockForUpdate()->firstOrFail();
            $rate = FundRateVersion::query()->latest('id')->lockForUpdate()->firstOrFail();
            $conversion = $this->conversion($rate, (int) $data['points']);
            if ($rate->id !== (int) $data['rate_version']) {
                throw new HttpResponseException(response()->json([
                    'code' => 'rate_changed', 'message' => '兑换比例已变更，请确认新金额。', 'data' => $conversion,
                ], 409));
            }

            $order = PlatformFundOrder::create([
                'member_id' => $owner->id, 'platform_id' => $platform->id,
                'platform_binding_id' => $binding->id, 'platform_account_id' => $binding->platform_account_id,
                'request_id' => $request, 'order_number' => (string) Str::uuid(), 'fund_rate_version_id' => $rate->id,
                'points' => $conversion['points'], 'cents_per_point' => $conversion['cents_per_point'],
                'amount_cents' => $conversion['amount_cents'], 'status' => 'pending',
            ]);
            $this->wallet->record($owner, -$order->points, [
                'operation_key' => (string) Str::uuid(), 'reason' => '平台资金兑换',
                'source' => 'platform_funds', 'source_id' => $order->id,
            ]);
            $this->event($order, 'submitted');

            return $order;
        }, 3);
    }

    public function approve(PlatformFundOrder $order, User $operator): void
    {
        abort_unless($operator->hasPermission('funds.review'), 403);

        $this->withLockedOrder($order, function (Platform $platform, Member $member, PlatformFundOrder $current) use ($operator): void {
            if ($current->task()->exists()) {
                return;
            }
            abort_unless($current->status === 'pending', 409, '只有待审核订单可以审核。');
            $this->requireAvailable($platform, $member);
            $current->forceFill(['status' => 'processing', 'reviewer_id' => $operator->id, 'reviewed_at' => now()])->save();
            $current->task()->create(['next_run_at' => now()]);
            $this->event($current, 'approved', $operator);
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
            $this->refund($member, $current, $reason, $operator);
            $current->forceFill([
                'status' => 'rejected', 'reviewer_id' => $operator->id, 'reviewed_at' => now(), 'review_reason' => $reason,
            ])->save();
            $this->event($current, 'rejected', $operator);
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

    private function conversion(FundRateVersion $rate, int $points): array
    {
        return [
            'rate_version' => $rate->id, 'points' => $points, 'cents_per_point' => $rate->cents_per_point,
            'amount_cents' => $points * $rate->cents_per_point, 'currency' => 'BRL',
        ];
    }
}
