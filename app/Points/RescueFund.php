<?php

namespace App\Points;

use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Models\PointRule;
use App\Models\RescueFundClaim;
use App\Platforms\PlatformAccount;
use App\Platforms\PlatformQueryFailure;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;

class RescueFund
{
    private const SETTLEMENT_SCALE = 100 * PointRules::SCALE;

    private const INELIGIBLE = '该平台暂不可领取救援金。';

    public function __construct(
        private readonly RescueFundSettings $settings,
        private readonly CashBalance $cash,
    ) {}

    public function eligible(Member $member): array
    {
        $settings = $this->settings->current();
        $data = [];
        $tiers = PlatformRebateTiers::effective(PointRule::query()->latest('id')->first());
        if (! $settings['enabled'] || $tiers === []) {
            return compact('data', 'settings');
        }

        $bindings = PlatformBinding::query()->whereBelongsTo($member)->where('protection_status', 'active')
            ->whereNotNull('rescue_baseline')->withWhereHas('platform', fn ($query) => $query->available()
            ->where('guarantee_amount_brl', '>', 0))->orderBy('id')->get();
        $providerUnavailable = false;
        foreach ($bindings as $binding) {
            $platform = $binding->platform;
            if (! $this->canQuery($binding, $platform)) {
                continue;
            }
            try {
                $account = $this->lookup($binding, $platform);
            } catch (PlatformQueryFailure) {
                $providerUnavailable = true;

                continue;
            }
            $quote = $this->quote($binding, $account?->rescueSnapshot(), $settings, $tiers);
            if ($quote !== null) {
                $data[] = ['binding_id' => $binding->id, 'platform_name' => $platform->name, ...$quote];
            }
        }

        abort_if($data === [] && $providerUnavailable, 503, '合作平台查询暂不可用。');

        return compact('data', 'settings');
    }

    public function claim(Member $member, string $bindingId, string $requestId): RescueFundClaim
    {
        $binding = PlatformBinding::query()->whereBelongsTo($member)->with('platform')->findOrFail($bindingId);
        $existing = $this->existingClaim($member, $binding, $requestId);
        if ($existing !== null) {
            return $existing;
        }

        $platform = $binding->platform;
        abort_unless($this->settings->current()['enabled'] && $this->canQuery($binding, $platform), 409, self::INELIGIBLE);
        try {
            $snapshot = $this->lookup($binding, $platform)?->rescueSnapshot();
        } catch (PlatformQueryFailure) {
            abort(503, '合作平台查询暂不可用。');
        }

        return DB::transaction(function () use ($member, $binding, $platform, $requestId, $snapshot): RescueFundClaim {
            $currentPlatform = Platform::query()->lockForUpdate()->findOrFail($platform->id);
            $owner = Member::query()->lockForUpdate()->findOrFail($member->id);
            abort_if($owner->disabled_at !== null, 403, '会员账号已禁用。');
            abort_if($owner->session_version !== $member->session_version, 401, '会员登录会话已失效。');
            $current = PlatformBinding::query()->whereBelongsTo($owner)->lockForUpdate()->findOrFail($binding->id);

            $existing = $this->existingClaim($owner, $current, $requestId);
            if ($existing !== null) {
                return $existing;
            }

            $settings = $this->settings->current(lock: true);
            abort_unless($settings['enabled'] && $this->canQuery($current, $currentPlatform)
                && $current->rescue_baseline === $binding->rescue_baseline
                && $current->platform_account_id === $binding->platform_account_id
                && $currentPlatform->network === $platform->network
                && $currentPlatform->active_query_revision === $platform->active_query_revision, 409, self::INELIGIBLE);

            // Rebate rule updates also lock the first rule before publishing a new version.
            PointRule::query()->oldest('id')->lockForUpdate()->firstOrFail();
            $rule = PointRule::query()->latest('id')->lockForUpdate()->firstOrFail();
            $quote = $this->quote($current, $snapshot, $settings, PlatformRebateTiers::effective($rule));
            abort_if($quote === null, 409, self::INELIGIBLE);

            $claim = RescueFundClaim::create([
                'member_id' => $owner->id, 'platform_id' => $currentPlatform->id,
                'platform_binding_id' => $current->id, 'platform_name' => $currentPlatform->name,
                'request_id' => $requestId, 'amount_cents' => $quote['amount_cents'],
                'calculation' => $quote + [
                    'rebate_rule_id' => $rule->id, 'settings' => $settings,
                    'period_start_snapshot' => $current->rescue_baseline, 'period_end_snapshot' => $snapshot,
                ],
                'credited_at' => now(),
            ]);
            $this->cash->refund($owner, $claim->amount_cents, 'rescue_fund', $claim->id);
            $current->forceFill(['rescue_baseline' => $snapshot])->save();

            return $claim;
        }, 3);
    }

    private function canQuery(PlatformBinding $binding, Platform $platform): bool
    {
        return $binding->protection_status === 'active' && $binding->rescue_baseline !== null
            && $platform->status() === 'available' && $platform->guarantee_amount_brl > 0
            && isset($platform->active_query['net_profit_field']);
    }

    private function lookup(PlatformBinding $binding, Platform $platform): ?PlatformAccount
    {
        $account = $platform->network->queryDriver()->lookup($platform->active_query, $binding->query_phone);

        return $account?->id === $binding->platform_account_id ? $account : null;
    }

    private function existingClaim(Member $member, PlatformBinding $binding, string $requestId): ?RescueFundClaim
    {
        $claim = RescueFundClaim::query()->where('member_id', $member->id)->where('request_id', $requestId)->first();
        abort_if($claim !== null && $claim->platform_binding_id !== $binding->id, 409, '领取请求已用于其他平台。');

        return $claim;
    }

    private function quote(PlatformBinding $binding, ?array $snapshot, array $settings, array $tiers): ?array
    {
        $baseline = $binding->rescue_baseline;
        // Snapshots store UTC ISO timestamps with the same precision and amount source.
        if ($snapshot === null || $baseline === null || $snapshot['source'] !== $baseline['source']
            || $snapshot['observed_at'] <= $baseline['observed_at']) {
            return null;
        }

        $deposit = $snapshot['deposit_cents'] - $baseline['deposit_cents'];
        $bet = $snapshot['valid_bet_cents'] - $baseline['valid_bet_cents'];
        $netLoss = BigInteger::of($baseline['net_profit_cents'])->minus($snapshot['net_profit_cents']);
        if ($deposit <= 0 || $deposit > CashBalance::MAX_CENTS || $netLoss->isLessThan($deposit) || $netLoss->isGreaterThan(CashBalance::MAX_CENTS)) {
            return null;
        }

        $tier = PlatformRebateTiers::matchWager($tiers, $deposit, $bet)['multiplier_micros'] ?? null;
        if ($tier === null || $tier > $settings['max_wager_tier_micros']) {
            return null;
        }

        $amount = BigInteger::of($deposit)->multipliedBy($settings['percent_micros'])->quotient(self::SETTLEMENT_SCALE);
        if ($amount->isZero() || $amount->isGreaterThan(CashBalance::MAX_CENTS)) {
            return null;
        }

        return [
            'deposit_cents' => $deposit, 'net_loss_cents' => $netLoss->toInt(),
            'wager_tier_micros' => $tier, 'amount_cents' => $amount->toInt(),
        ];
    }
}
