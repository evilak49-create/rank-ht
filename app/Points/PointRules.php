<?php

namespace App\Points;

use App\Models\PointRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PointRules
{
    public const SCALE = 1_000_000;

    public static function display(int $units, int $places = 6): string
    {
        $scale = 10 ** $places;

        return intdiv($units, $scale).'.'.str_pad((string) ($units % $scale), $places, '0', STR_PAD_LEFT);
    }

    public function save(array $data, User $operator): PointRule
    {
        abort_unless($operator->hasPermission('points.manage-rules'), 403);
        $fields = Validator::make($data, [
            'points_per_brl' => ['required', 'regex:/\A[0-9]{1,5}(?:\.[0-9]{1,6})?\z/'],
            'wager_multiplier' => ['required', 'regex:/\A[0-9]{1,5}(?:\.[0-9]{1,6})?\z/'],
        ])->validate();
        $ratio = self::toUnits($fields['points_per_brl']);
        $wager = self::toUnits($fields['wager_multiplier']);
        if ($ratio === 0 || $wager === 0) {
            throw ValidationException::withMessages([$ratio === 0 ? 'points_per_brl' : 'wager_multiplier' => '请输入大于零的数值。']);
        }

        return $this->version([
            'points_per_brl_micros' => $ratio, 'wager_multiplier_micros' => $wager,
        ], $operator);
    }

    public function saveRebate(array $data, User $operator): PointRule
    {
        abort_unless($operator->hasPermission('points.manage-rules'), 403);
        $fields = Validator::make($data, [
            'rebate_percent' => ['required', 'regex:/\A[0-9]{1,3}(?:\.[0-9]{1,6})?\z/'],
            'rebate_wager_multiplier' => ['required', 'regex:/\A[0-9]{1,5}(?:\.[0-9]{1,6})?\z/'],
        ])->validate();
        $percent = self::toUnits($fields['rebate_percent']);
        $wager = self::toUnits($fields['rebate_wager_multiplier']);
        if ($percent > 100 * self::SCALE) {
            throw ValidationException::withMessages(['rebate_percent' => '返利比例须在 0% 至 100% 之间。']);
        }
        if ($wager === 0) {
            throw ValidationException::withMessages(['rebate_wager_multiplier' => '投注倍数必须大于零。']);
        }

        return $this->version([
            'rebate_percent_micros' => $percent, 'rebate_wager_multiplier_micros' => $wager,
        ], $operator);
    }

    private function version(array $changes, User $operator): PointRule
    {
        return DB::transaction(function () use ($changes, $operator): PointRule {
            PointRule::query()->oldest('id')->lockForUpdate()->firstOrFail();
            $active = PointRule::query()->latest('id')->lockForUpdate()->firstOrFail();

            return PointRule::create($changes + ['user_id' => $operator->id] + $active->only([
                'points_per_brl_micros', 'wager_multiplier_micros', 'rebate_percent_micros', 'rebate_wager_multiplier_micros',
            ]));
        }, 3);
    }

    public static function toUnits(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

        return (int) $whole * self::SCALE + (int) str_pad($fraction, 6, '0');
    }
}
