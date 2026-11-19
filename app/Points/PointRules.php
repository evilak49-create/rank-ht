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
        $ratio = $this->toUnits($fields['points_per_brl']);
        $wager = $this->toUnits($fields['wager_multiplier']);
        if ($ratio === 0 || $wager === 0) {
            throw ValidationException::withMessages([$ratio === 0 ? 'points_per_brl' : 'wager_multiplier' => '请输入大于零的数值。']);
        }

        return DB::transaction(function () use ($operator, $ratio, $wager): PointRule {
            PointRule::query()->oldest('id')->lockForUpdate()->firstOrFail();

            return PointRule::create([
                'points_per_brl_micros' => $ratio, 'wager_multiplier_micros' => $wager, 'user_id' => $operator->id,
            ]);
        }, 3);
    }

    private function toUnits(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');

        return (int) $whole * self::SCALE + (int) str_pad($fraction, 6, '0');
    }
}
