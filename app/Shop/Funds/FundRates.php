<?php

namespace App\Shop\Funds;

use App\Models\FundRateVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FundRates
{
    public function save(string $value, User $operator): FundRateVersion
    {
        abort_unless($operator->hasPermission('points.manage-rules'), 403);
        if (! preg_match('/\A([0-9]{1,6})(?:\.([0-9]{1,2}))?\z/', $value, $matches)) {
            throw ValidationException::withMessages(['rate' => '请输入最多两位小数的 BRL 金额。']);
        }

        $cents = (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
        if ($cents < 1) {
            throw ValidationException::withMessages(['rate' => '每积分到账金额必须大于零。']);
        }

        return DB::transaction(function () use ($cents, $operator): FundRateVersion {
            FundRateVersion::query()->oldest('id')->lockForUpdate()->firstOrFail();

            return FundRateVersion::create(['cents_per_point' => $cents, 'user_id' => $operator->id]);
        }, 3);
    }
}
