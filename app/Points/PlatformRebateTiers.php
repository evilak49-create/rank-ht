<?php

namespace App\Points;

use App\Models\Platform;
use App\Models\PointRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PlatformRebateTiers
{
    public const MULTIPLIER_RULES = ['required', 'numeric', 'min:0.000001', 'regex:/\A[0-9]{1,5}(?:\.[0-9]{1,6})?\z/'];

    public const PERCENT_RULES = ['required', 'numeric', 'between:0.000001,100', 'regex:/\A[0-9]{1,3}(?:\.[0-9]{1,6})?\z/'];

    public static function effective(Platform $platform, ?PointRule $default): array
    {
        if ($platform->rebate_tiers !== null) {
            return $platform->rebate_tiers;
        }
        if ($default === null || $default->rebate_percent_micros <= 0) {
            return [];
        }

        return [[
            'multiplier_micros' => $default->rebate_wager_multiplier_micros ?? $default->wager_multiplier_micros,
            'percent_micros' => $default->rebate_percent_micros,
        ]];
    }

    public static function summary(array $tiers): array
    {
        return [
            'min_rebate_wager_multiplier' => $tiers === [] ? null : PointRules::display($tiers[0]['multiplier_micros']),
            'max_rebate_percent' => $tiers === [] ? null : PointRules::display(max(array_column($tiers, 'percent_micros'))),
        ];
    }

    public static function decimals(array $tiers): array
    {
        return array_map(fn (array $tier): array => [
            'wager_multiplier' => PointRules::display($tier['multiplier_micros']),
            'rebate_percent' => PointRules::display($tier['percent_micros']),
        ], $tiers);
    }

    public function save(Platform $platform, array $data, User $operator): void
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

        DB::transaction(function () use ($platform, $tiers, $operator): void {
            Platform::query()->lockForUpdate()->findOrFail($platform->id)
                ->forceFill(['rebate_tiers' => array_values($tiers)])->save();
            DB::table('platform_audits')->insert([
                'platform_id' => $platform->id, 'user_id' => $operator->id,
                'action' => 'rebate_tiers_updated', 'created_at' => now(),
            ]);
        }, 3);
    }
}
