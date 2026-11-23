<?php

namespace App\Points;

use App\Models\PointRule;
use App\Models\User;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PlatformRebateTiers
{
    public const MULTIPLIER_RULES = ['required', 'numeric', 'min:0.000001', 'regex:/\A[0-9]{1,5}(?:\.[0-9]{1,6})?\z/'];

    public const PERCENT_RULES = ['required', 'numeric', 'between:0.000001,100', 'regex:/\A[0-9]{1,3}(?:\.[0-9]{1,6})?\z/'];

    public static function effective(?PointRule $rule): array
    {
        if ($rule?->rebate_tiers !== null) {
            return $rule->rebate_tiers;
        }
        if ($rule === null || $rule->rebate_percent_micros <= 0) {
            return [];
        }

        return [[
            'multiplier_micros' => $rule->rebate_wager_multiplier_micros ?? $rule->wager_multiplier_micros,
            'percent_micros' => $rule->rebate_percent_micros,
        ]];
    }

    public static function summary(array $tiers): array
    {
        return [
            'min_rebate_wager_multiplier' => $tiers === [] ? null : PointRules::display($tiers[0]['multiplier_micros']),
            'max_rebate_percent' => $tiers === [] ? null : PointRules::display(max(array_column($tiers, 'percent_micros'))),
        ];
    }

    public static function matchWager(array $tiers, int $deposit, int $bet): ?array
    {
        if ($deposit <= 0 || $bet < 0) {
            return null;
        }

        $scaledBet = BigInteger::of($bet)->multipliedBy(PointRules::SCALE);
        $depositBase = BigInteger::of($deposit);
        foreach (array_reverse($tiers) as $tier) {
            if (! $depositBase->multipliedBy($tier['multiplier_micros'])->isGreaterThan($scaledBet)) {
                return $tier;
            }
        }

        return null;
    }

    public static function decimals(array $tiers): array
    {
        return array_map(fn (array $tier): array => [
            'wager_multiplier' => PointRules::display($tier['multiplier_micros']),
            'rebate_percent' => PointRules::display($tier['percent_micros']),
        ], $tiers);
    }

    public function save(array $data, User $operator): PointRule
    {
        abort_unless($operator->hasPermission('points.manage-rules'), 403);
        $fields = Validator::make($data, [
            'tiers' => ['present', 'array'],
            'tiers.*' => ['array:wager_multiplier,rebate_percent'],
            'tiers.*.wager_multiplier' => self::MULTIPLIER_RULES,
            'tiers.*.rebate_percent' => self::PERCENT_RULES,
        ])->validate();

        $tiers = [];
        foreach ($fields['tiers'] as $tier) {
            $multiplier = PointRules::toUnits($tier['wager_multiplier']);
            if (isset($tiers[$multiplier])) {
                throw ValidationException::withMessages(['tiers' => '打码倍数不能重复。']);
            }
            $tiers[$multiplier] = ['multiplier_micros' => $multiplier, 'percent_micros' => PointRules::toUnits($tier['rebate_percent'])];
        }
        ksort($tiers, SORT_NUMERIC);

        $tiers = array_values($tiers);

        return DB::transaction(function () use ($tiers, $operator): PointRule {
            PointRule::query()->oldest('id')->lockForUpdate()->firstOrFail();
            $active = PointRule::query()->latest('id')->lockForUpdate()->firstOrFail();

            return PointRule::create($active->only(['points_per_brl_micros', 'wager_multiplier_micros']) + [
                'rebate_tiers' => $tiers, 'user_id' => $operator->id,
                'rebate_percent_micros' => $tiers[0]['percent_micros'] ?? 0,
                'rebate_wager_multiplier_micros' => $tiers[0]['multiplier_micros'] ?? null,
            ]);
        }, 3);
    }
}
