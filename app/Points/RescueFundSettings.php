<?php

namespace App\Points;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RescueFundSettings
{
    public const MANAGE = 'points.manage-rules';

    public const WAGER_RULES = ['required', 'numeric', 'min:0', 'regex:/\A[0-9]{1,5}(?:\.[0-9]{1,6})?\z/'];

    public const PERCENT_RULES = ['required', 'numeric', 'between:0,100', 'regex:/\A[0-9]{1,3}(?:\.[0-9]{1,6})?\z/'];

    public function current(bool $lock = false): array
    {
        $settings = DB::table('rescue_fund_settings')->where('id', 1)
            ->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();

        return [
            'enabled' => (bool) $settings->enabled,
            'max_wager_tier_micros' => (int) $settings->max_wager_tier_micros,
            'percent_micros' => (int) $settings->percent_micros,
            'loss_percent_micros' => (int) $settings->loss_percent_micros,
        ];
    }

    public function save(array $data, User $operator): void
    {
        abort_unless($operator->hasPermission(self::MANAGE), 403);
        $data = Validator::make($data, [
            'enabled' => ['required', 'boolean'],
            'max_wager_tier' => self::WAGER_RULES,
            'percent' => self::PERCENT_RULES,
            'loss_percent' => self::PERCENT_RULES,
        ])->validate();
        $tier = PointRules::toUnits((string) $data['max_wager_tier']);
        $percent = PointRules::toUnits((string) $data['percent']);
        if ($data['enabled'] && ($tier === 0 || $percent === 0)) {
            throw ValidationException::withMessages([
                $tier === 0 ? 'max_wager_tier' : 'percent' => '启用救援金时，最高可领档位和救援金比例须大于零。',
            ]);
        }

        DB::table('rescue_fund_settings')->where('id', 1)->update([
            'enabled' => $data['enabled'], 'max_wager_tier_micros' => $tier, 'percent_micros' => $percent,
            'loss_percent_micros' => PointRules::toUnits((string) $data['loss_percent']),
            'user_id' => $operator->id, 'updated_at' => now(),
        ]);
    }
}
