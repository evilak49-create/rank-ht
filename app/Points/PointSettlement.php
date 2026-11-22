<?php

namespace App\Points;

use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Models\PointQuote;
use App\Models\PointRule;
use App\Platforms\PlatformAccount;
use App\Platforms\PlatformAdministration;
use App\Platforms\PlatformQueryFailure;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;

class PointSettlement
{
    private const SETTLEMENT_SCALE = 100_000_000;

    public function __construct(
        private readonly PlatformAdministration $platforms,
        private readonly CashBalance $cash,
    ) {}

    public function totals(Member $member, string $bindingId): array
    {
        $binding = PlatformBinding::query()->where('member_id', $member->id)->findOrFail($bindingId);
        $platform = Platform::query()->available()->findOrFail($binding->platform_id);
        $account = $this->lookupBoundAccount($binding, $platform);

        return [
            'total_deposit_cents' => (string) $account->depositCents,
            'total_valid_bet_cents' => (string) $account->validBetCents,
        ];
    }

    public function quote(Member $member, string $bindingId): PointQuote
    {
        $binding = PlatformBinding::query()->where('member_id', $member->id)->findOrFail($bindingId);
        $platform = Platform::query()->available()->findOrFail($binding->platform_id);
        $account = $this->lookupBoundAccount($binding, $platform);

        return DB::transaction(function () use ($member, $binding, $platform, $account): PointQuote {
            $currentPlatform = $this->lockPlatform($platform);
            $this->lockMember($member);
            $current = PlatformBinding::query()->lockForUpdate()->findOrFail($binding->id);
            abort_unless($current->baseline_version === $binding->baseline_version, 409, '结算基线已变更，请重新查询。');

            return PointQuote::create([
                'member_id' => $member->id, 'platform_id' => $platform->id, 'platform_binding_id' => $binding->id,
                'baseline_version' => $current->baseline_version,
                ...$this->quoteValues($current, $currentPlatform, $account),
                'expires_at' => now()->addMinutes(10),
            ]);
        }, 3);
    }

    public function confirm(Member $member, string $quoteId): PointQuote
    {
        $quote = PointQuote::query()->where('member_id', $member->id)->findOrFail($quoteId);
        if ($quote->confirmed_at !== null) {
            return $quote;
        }
        abort_unless($quote->status() === 'pending', 409, '报价已过期，请重新查询。');
        $binding = PlatformBinding::query()->findOrFail($quote->platform_binding_id);
        if ($binding->baseline_version !== $quote->baseline_version) {
            $quote->refresh();
            abort_unless($quote->confirmed_at !== null, 409, '结算基线已更新，请重新查询。');

            return $quote;
        }
        abort_if($binding->settlement_paused_at !== null, 409, '该报价暂不可兑换。');
        $platform = Platform::query()->available()->findOrFail($quote->platform_id);
        $account = $this->lookupBoundAccount($binding, $platform);

        $result = DB::transaction(function () use ($member, $quote, $platform, $account): PointQuote {
            $currentPlatform = $this->lockPlatform($platform);
            $owner = $this->lockMember($member);
            $binding = PlatformBinding::query()->lockForUpdate()->findOrFail($quote->platform_binding_id);
            $current = PointQuote::query()->lockForUpdate()->findOrFail($quote->id);
            if ($current->confirmed_at !== null) {
                return $current;
            }

            abort_unless($current->status() === 'pending', 409, '报价已过期，请重新查询。');
            abort_unless($binding->baseline_version === $current->baseline_version, 409, '结算基线已更新，请重新查询。');

            $current->fill($this->quoteValues($binding, $currentPlatform, $account));
            if ($current->eligibility !== 'eligible') {
                $current->save();

                return $current;
            }

            $this->cash->creditRebate($owner, $current->rebate_cents, $current->id);
            $snapshot = $current->snapshot;
            $binding->forceFill([
                'baseline_deposit_cents' => $snapshot['observed_deposit_cents'],
                'baseline_valid_bet_cents' => $snapshot['observed_valid_bet_cents'],
                'baseline_observed_at' => $snapshot['observed_at'],
                'baseline_version' => $binding->baseline_version + 1,
            ])->save();

            $current->forceFill(['confirmed_at' => now()])->save();

            return $current;
        }, 3);

        abort_if($result->confirmed_at === null, 409, $result->eligibility === 'insufficient_wager'
            ? '当前打码未达到返利标准'
            : '该报价暂不可兑换。');

        return $result;
    }

    private function lockPlatform(Platform $platform): Platform
    {
        $current = Platform::query()->lockForUpdate()->findOrFail($platform->id);
        abort_unless($current->status() === 'available', 404);
        abort_unless($current->network === $platform->network && $current->active_query_revision === $platform->active_query_revision, 409, '平台配置已变更，请重新查询。');

        return $current;
    }

    private function lookupBoundAccount(PlatformBinding $binding, Platform $platform): PlatformAccount
    {
        try {
            $account = $platform->network->queryDriver()->lookup($platform->active_query, $binding->query_phone);
        } catch (PlatformQueryFailure $failure) {
            $this->platforms->recordQueryOutcome($platform, $failure->kind);
            abort($failure->kind === 'ambiguous' ? 409 : 503, $failure->getMessage());
        }

        $this->platforms->recordQueryOutcome($platform, $account === null ? 'not_found' : 'passed');
        abort_if($account === null || $account->id !== $binding->platform_account_id, 409, '未能核对已绑定的合作方账号。');

        return $account;
    }

    private function lockMember(Member $member): Member
    {
        $owner = Member::query()->lockForUpdate()->findOrFail($member->id);
        abort_if($owner->disabled_at !== null, 403, '会员账号已禁用。');
        abort_if($owner->session_version !== $member->session_version, 401, '会员登录会话已失效。');

        return $owner;
    }

    private function quoteValues(PlatformBinding $binding, Platform $platform, PlatformAccount $account): array
    {
        $rule = PointRule::query()->latest('id')->firstOrFail();
        $tiers = PlatformRebateTiers::effective($platform, $rule);
        $deposit = $account->depositCents - $binding->baseline_deposit_cents;
        $bet = $account->validBetCents - $binding->baseline_valid_bet_cents;
        $depositBase = BigInteger::of(max(0, $deposit));
        $minimumMultiplier = $tiers[0]['multiplier_micros'] ?? null;
        $required = $this->wagerRequired($depositBase, $minimumMultiplier ?? 0);
        $matched = null;
        if ($deposit > 0) {
            foreach (array_reverse($tiers) as $tier) {
                if (! $this->wagerRequired($depositBase, $tier['multiplier_micros'])->isGreaterThan($bet)) {
                    $matched = $tier;
                    break;
                }
            }
        }
        $percent = $matched['percent_micros'] ?? 0;
        $rebate = $depositBase->multipliedBy($percent)->quotient(self::SETTLEMENT_SCALE);

        $eligibility = match (true) {
            $binding->settlement_paused_at !== null => 'paused',
            $account->observedAt->lt($binding->baseline_observed_at) => 'stale_snapshot',
            $deposit < 0 || $bet < 0 => 'regressed',
            $deposit === 0 => 'no_deposit',
            $tiers === [] => 'rebate_disabled',
            $matched === null => 'insufficient_wager',
            $rebate->isZero() => 'insufficient_rebate',
            $rebate->isGreaterThan(CashBalance::MAX_CENTS) => 'out_of_range',
            default => 'eligible',
        };
        if ($eligibility === 'regressed') {
            $binding->forceFill(['settlement_paused_at' => now()])->save();
        }

        return [
            'point_rule_id' => $rule->id,
            'eligibility' => $eligibility,
            'estimated_points' => 0,
            'rebate_cents' => $eligibility === 'eligible' ? $rebate->toInt() : 0,
            'snapshot' => [
                'baseline_observed_at' => $binding->baseline_observed_at->toISOString(),
                'observed_at' => $account->observedAt->toISOString(),
                'observed_deposit_cents' => $account->depositCents,
                'observed_valid_bet_cents' => $account->validBetCents,
                'deposit_cents' => $deposit, 'valid_bet_cents' => $bet,
                'required_bet_cents' => $this->amount($required),
                'point_required_bet_cents' => 0,
                'rebate_required_bet_cents' => $minimumMultiplier === null ? null : $this->amount($required),
                'points_per_brl' => PointRules::display(0),
                'wager_multiplier' => PointRules::display(0),
                'cash_only' => true,
                'rebate_enabled' => $tiers !== [],
                'rebate_percent' => PointRules::display($percent),
                'rebate_wager_multiplier' => $minimumMultiplier === null ? null : PointRules::display($minimumMultiplier),
                'matched_rebate_wager_multiplier' => $matched === null ? null : PointRules::display($matched['multiplier_micros']),
                ...PlatformRebateTiers::summary($tiers),
                'remainder_before' => PointRules::display($binding->point_remainder, 8),
                'remainder_after' => PointRules::display($binding->point_remainder, 8),
            ],
        ];
    }

    private function wagerRequired(BigInteger $deposit, int $multiplier): BigInteger
    {
        return $deposit->multipliedBy($multiplier)->plus(PointRules::SCALE - 1)->quotient(PointRules::SCALE);
    }

    private function amount(BigInteger $value): int|string
    {
        return $value->isGreaterThan(PHP_INT_MAX) ? (string) $value : $value->toInt();
    }
}
