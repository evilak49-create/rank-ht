<?php

namespace App\Points;

use App\Models\Member;
use App\Models\Platform;
use App\Models\PlatformBinding;
use App\Models\PointQuote;
use App\Models\PointRule;
use App\Platforms\PlatformAccount;
use App\Platforms\PlatformAdministration;
use App\Platforms\PlatformQueryClient;
use App\Platforms\PlatformQueryFailure;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PointSettlement
{
    private const POINT_SCALE = 100_000_000;

    public function __construct(
        private readonly PlatformQueryClient $client,
        private readonly PlatformAdministration $platforms,
        private readonly PointWallet $wallet,
    ) {}

    public function quote(Member $member, string $bindingId): PointQuote
    {
        $binding = PlatformBinding::query()->where('member_id', $member->id)->findOrFail($bindingId);
        $platform = Platform::query()->available()->findOrFail($binding->platform_id);
        $rule = PointRule::query()->latest('id')->firstOrFail();

        try {
            $account = $this->client->lookup($platform->active_query, $binding->query_phone);
        } catch (PlatformQueryFailure $failure) {
            $this->platforms->recordQueryOutcome($platform, $failure->kind);
            abort($failure->kind === 'ambiguous' ? 409 : 503, $failure->getMessage());
        }

        $this->platforms->recordQueryOutcome($platform, $account === null ? 'not_found' : 'passed');
        abort_if($account === null || $account->id !== $binding->platform_account_id, 409, '未能核对已绑定的合作方账号。');

        return DB::transaction(function () use ($member, $binding, $platform, $rule, $account): PointQuote {
            $currentPlatform = Platform::query()->lockForUpdate()->findOrFail($platform->id);
            abort_unless($currentPlatform->status() === 'available', 404);
            abort_unless($currentPlatform->active_query_revision === $platform->active_query_revision, 409, '平台配置已变更，请重新查询。');

            $this->lockMember($member);
            $current = PlatformBinding::query()->lockForUpdate()->findOrFail($binding->id);
            abort_unless($current->baseline_version === $binding->baseline_version, 409, '结算基线已变更，请重新查询。');

            [$eligibility, $estimated, $snapshot] = $this->calculate($current, $rule, $account);
            if ($eligibility === 'regressed') {
                $current->forceFill(['settlement_paused_at' => now()])->save();
            }

            return PointQuote::create([
                'member_id' => $member->id, 'platform_id' => $platform->id, 'platform_binding_id' => $binding->id,
                'point_rule_id' => $rule->id, 'baseline_version' => $current->baseline_version,
                'estimated_points' => $estimated, 'eligibility' => $eligibility, 'snapshot' => $snapshot,
                'expires_at' => now()->addMinutes(10),
            ]);
        }, 3);
    }

    public function confirm(Member $member, string $quoteId): PointQuote
    {
        $quote = PointQuote::query()->where('member_id', $member->id)->findOrFail($quoteId);

        return DB::transaction(function () use ($member, $quote): PointQuote {
            $platform = Platform::query()->lockForUpdate()->findOrFail($quote->platform_id);
            $owner = $this->lockMember($member);
            $binding = PlatformBinding::query()->lockForUpdate()->findOrFail($quote->platform_binding_id);
            $current = PointQuote::query()->lockForUpdate()->findOrFail($quote->id);
            if ($current->confirmed_at !== null) {
                return $current;
            }

            abort_unless($platform->status() === 'available', 404);
            abort_unless($current->status() === 'pending', 409, '报价已过期，请重新查询。');
            abort_unless($current->eligibility === 'eligible' && $binding->settlement_paused_at === null, 409, '该报价暂不可兑换。');
            abort_unless($binding->baseline_version === $current->baseline_version, 409, '结算基线已更新，请重新查询。');

            $entry = $this->wallet->record($owner, $current->estimated_points, [
                'operation_key' => (string) Str::uuid(), 'reason' => '存款换积分',
                'source' => 'deposit', 'source_id' => $current->id,
            ]);

            $snapshot = $current->snapshot;
            $binding->forceFill([
                'baseline_deposit_cents' => $snapshot['observed_deposit_cents'],
                'baseline_valid_bet_cents' => $snapshot['observed_valid_bet_cents'],
                'baseline_observed_at' => $snapshot['observed_at'],
                'baseline_version' => $binding->baseline_version + 1,
                'point_remainder' => $snapshot['remainder_units'],
            ])->save();

            $current->forceFill(['point_entry_id' => $entry->id, 'confirmed_at' => now()])->save();

            return $current;
        }, 3);
    }

    private function lockMember(Member $member): Member
    {
        $owner = Member::query()->lockForUpdate()->findOrFail($member->id);
        abort_if($owner->disabled_at !== null, 403, '会员账号已禁用。');
        abort_if($owner->session_version !== $member->session_version, 401, '会员登录会话已失效。');

        return $owner;
    }

    private function calculate(PlatformBinding $binding, PointRule $rule, PlatformAccount $account): array
    {
        $deposit = $account->depositCents - $binding->baseline_deposit_cents;
        $bet = $account->validBetCents - $binding->baseline_valid_bet_cents;
        $required = BigInteger::of(max(0, $deposit))->multipliedBy($rule->wager_multiplier_micros)
            ->plus(PointRules::SCALE - 1)->quotient(PointRules::SCALE);
        [$whole, $fraction] = BigInteger::of(max(0, $deposit))->multipliedBy($rule->points_per_brl_micros)
            ->plus($binding->point_remainder)->quotientAndRemainder(self::POINT_SCALE);

        $eligibility = match (true) {
            $binding->settlement_paused_at !== null => 'paused',
            $account->observedAt->lt($binding->baseline_observed_at) => 'stale_snapshot',
            $deposit < 0 || $bet < 0 => 'regressed',
            $deposit === 0 => 'no_deposit',
            $required->isGreaterThan($bet) => 'insufficient_wager',
            $whole->isZero() => 'insufficient_points',
            $whole->isGreaterThan(PHP_INT_MAX) => 'out_of_range',
            default => 'eligible',
        };

        $estimated = in_array($eligibility, ['eligible', 'insufficient_wager'], true) && ! $whole->isGreaterThan(PHP_INT_MAX)
            ? $whole->toInt() : 0;

        return [$eligibility, $estimated, [
            'baseline_observed_at' => $binding->baseline_observed_at->toISOString(),
            'observed_at' => $account->observedAt->toISOString(),
            'observed_deposit_cents' => $account->depositCents,
            'observed_valid_bet_cents' => $account->validBetCents,
            'deposit_cents' => $deposit, 'valid_bet_cents' => $bet,
            'required_bet_cents' => $required->isGreaterThan(PHP_INT_MAX) ? (string) $required : $required->toInt(),
            'points_per_brl' => PointRules::display($rule->points_per_brl_micros),
            'wager_multiplier' => PointRules::display($rule->wager_multiplier_micros),
            'remainder_before' => PointRules::display($binding->point_remainder, 8),
            'remainder_after' => PointRules::display($eligibility === 'eligible' ? $fraction->toInt() : $binding->point_remainder, 8),
            'remainder_units' => $eligibility === 'eligible' ? $fraction->toInt() : $binding->point_remainder,
        ]];
    }
}
