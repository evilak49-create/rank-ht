<?php

namespace App\Points;

use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Models\PointRule;
use App\Models\RescueFundClaim;
use App\Platforms\PlatformAccount;
use App\Platforms\PlatformNetwork;
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
        $unavailable = [];
        $tiers = $settings['enabled'] ? PlatformRebateTiers::effective(PointRule::query()->latest('id')->first()) : [];
        $bindings = PlatformBinding::query()->whereBelongsTo($member)->with('platform')->orderBy('id')->get();
        foreach ($bindings as $binding) {
            $platform = $binding->platform;
            $entry = ['binding_id' => $binding->id, 'platform_name' => $platform->name, 'logo_url' => $platform->logo_url];
            if ($tiers === [] || ! $this->canQuery($platform)) {
                $unavailable[] = $entry;

                continue;
            }
            try {
                $account = $this->lookup($binding, $platform);
            } catch (PlatformQueryFailure) {
                $unavailable[] = $entry + ['query_unavailable' => true];

                continue;
            }
            $snapshot = $account?->rescueSnapshot();
            if ($binding->rescue_baseline === null && $snapshot !== null) {
                $this->initializeBaseline($binding, $platform, $snapshot);
            }
            $assessment = $this->assess($binding, $snapshot, $settings, $tiers);
            if (($assessment['amount_cents'] ?? 0) === 0) {
                $unavailable[] = $entry + $assessment;
            } else {
                $data[] = $entry + $assessment;
            }
        }

        return compact('data', 'unavailable', 'settings');
    }

    public function claim(Member $member, string $bindingId, string $requestId): RescueFundClaim
    {
        $binding = PlatformBinding::query()->whereBelongsTo($member)->with('platform')->findOrFail($bindingId);
        $existing = $this->existingClaim($member, $binding, $requestId);
        if ($existing !== null) {
            return $existing;
        }

        $platform = $binding->platform;
        abort_unless($this->settings->current()['enabled'] && $this->canQuery($platform)
            && $binding->rescue_baseline !== null, 409, self::INELIGIBLE);
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
            abort_unless($settings['enabled'] && $this->canQuery($currentPlatform)
                && $current->rescue_baseline === $binding->rescue_baseline
                && $current->platform_account_id === $binding->platform_account_id
                && $currentPlatform->network === $platform->network
                && $currentPlatform->active_query_revision === $platform->active_query_revision, 409, self::INELIGIBLE);

            // Rebate rule updates also lock the first rule before publishing a new version.
            PointRule::query()->oldest('id')->lockForUpdate()->firstOrFail();
            $rule = PointRule::query()->latest('id')->lockForUpdate()->firstOrFail();
            $quote = $this->assess($current, $snapshot, $settings, PlatformRebateTiers::effective($rule));
            abort_if(($quote['amount_cents'] ?? 0) === 0, 409, self::INELIGIBLE);

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

    public function inspectBinding(PlatformBinding $binding): array
    {
        $settings = $this->settings->current();
        $platform = $binding->platform;
        $baseline = ['baseline_at' => $binding->rescue_baseline['observed_at'] ?? null];
        if (! $settings['enabled']) {
            return $baseline + ['result' => '救援金未开放'];
        }
        if (! $this->canQuery($platform)) {
            return $baseline + ['result' => '平台或绑定当前不支持救援金'];
        }
        if ($binding->rescue_baseline === null) {
            return $baseline + ['result' => '等待建立有效基线'];
        }
        $tiers = PlatformRebateTiers::effective(PointRule::query()->latest('id')->first());
        if ($tiers === []) {
            return $baseline + ['result' => '暂无返利档位'];
        }
        try {
            $snapshot = $this->lookup($binding, $platform)?->rescueSnapshot();
        } catch (PlatformQueryFailure) {
            return $baseline + ['result' => '合作平台查询暂不可用'];
        }
        $assessment = $this->assess($binding, $snapshot, $settings, $tiers);
        if ($assessment === []) {
            return $baseline + ['result' => '未取得可与基线比较的有效数据'];
        }

        return $baseline + $assessment + ['result' => $assessment['amount_cents'] > 0 ? '可领取' : '本期尚未达标'];
    }

    private function canQuery(Platform $platform): bool
    {
        return $platform->status() === 'available' && $platform->guarantee_amount_brl > 0
            && ($platform->network === PlatformNetwork::Panda || isset($platform->active_query['net_profit_field']));
    }

    private function initializeBaseline(PlatformBinding $binding, Platform $platform, array $snapshot): void
    {
        DB::transaction(function () use ($binding, $platform, $snapshot): void {
            $currentPlatform = Platform::query()->lockForUpdate()->find($platform->id);
            if ($currentPlatform === null || $currentPlatform->network !== $platform->network
                || $currentPlatform->active_query_revision !== $platform->active_query_revision) {
                return;
            }

            $current = PlatformBinding::query()->lockForUpdate()->find($binding->id);
            if ($current !== null && $current->rescue_baseline === null
                && $current->platform_id === $platform->id && $current->platform_account_id === $binding->platform_account_id
                && $this->canQuery($currentPlatform)) {
                $current->forceFill(['rescue_baseline' => $snapshot])->save();
            }
        }, 3);
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

    private function assess(PlatformBinding $binding, ?array $snapshot, array $settings, array $tiers): array
    {
        $baseline = $binding->rescue_baseline;
        // Snapshots store UTC ISO timestamps with the same precision and amount source.
        if ($snapshot === null || $baseline === null || $snapshot['source'] !== $baseline['source']
            || $snapshot['observed_at'] <= $baseline['observed_at']) {
            return [];
        }

        $deposit = $snapshot['deposit_cents'] - $baseline['deposit_cents'];
        $bet = $snapshot['valid_bet_cents'] - $baseline['valid_bet_cents'];
        $netLoss = BigInteger::of($baseline['net_profit_cents'])->minus($snapshot['net_profit_cents']);
        if ($deposit < 0 || $deposit > CashBalance::MAX_CENTS || $netLoss->isGreaterThan(CashBalance::MAX_CENTS)) {
            return [];
        }

        $tier = PlatformRebateTiers::matchWager($tiers, $deposit, $bet)['multiplier_micros'] ?? null;
        $details = [
            'deposit_cents' => $deposit, 'net_loss_cents' => $netLoss->isNegative() ? 0 : $netLoss->toInt(),
            'wager_tier_micros' => $tier, 'amount_cents' => 0,
        ];
        if ($deposit === 0 || $netLoss->isLessThan($deposit) || $tier === null || $tier > $settings['max_wager_tier_micros']) {
            return $details;
        }

        $amount = BigInteger::of($deposit)->multipliedBy($settings['percent_micros'])->quotient(self::SETTLEMENT_SCALE);
        if (! $amount->isGreaterThan(CashBalance::MAX_CENTS)) {
            $details['amount_cents'] = $amount->toInt();
        }

        return $details;
    }
}
