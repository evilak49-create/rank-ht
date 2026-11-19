<?php

namespace App\Activities;

use App\Models\BusinessTimezone;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BusinessClock
{
    public function current(CarbonInterface $at): string
    {
        return BusinessTimezone::query()
            ->where(fn ($query) => $query->whereNull('effective_at')->orWhere('effective_at', '<=', $at))
            ->latest('id')->value('timezone') ?? 'America/Sao_Paulo';
    }

    public function schedule(string $timezone, User $operator): BusinessTimezone
    {
        abort_unless($operator->hasPermission('system.manage-business-timezone'), 403);
        Validator::make(['timezone' => $timezone], ['timezone' => 'required|timezone'])->validate();

        return DB::transaction(function () use ($timezone, $operator): BusinessTimezone {
            BusinessTimezone::query()->oldest('id')->lockForUpdate()->firstOrFail();
            $now = now()->utc();
            $active = $this->current($now);
            $switch = $now->copy()->setTimezone($active)->addDay()->startOfDay()->utc();

            return BusinessTimezone::create(['timezone' => $timezone, 'effective_at' => $switch, 'user_id' => $operator->id]);
        }, 3);
    }
}
