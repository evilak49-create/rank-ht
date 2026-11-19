<?php

namespace App\Auth;

use App\Members\MemberAdministration;
use App\Models\AdminRole;
use App\Models\User;
use App\Platforms\PlatformAdministration;
use App\Shop\Funds\PlatformFunds;
use App\Shop\PhysicalShop;
use Illuminate\Support\Facades\DB;

class AdminAccountCreator
{
    public function create(string $username, string $name, string $password): void
    {
        DB::transaction(function () use ($username, $name, $password): void {
            $role = AdminRole::query()->firstOrCreate(
                ['name' => '会员运营'],
                ['permissions' => [
                    'members.view', 'members.change-status', ...MemberAdministration::PERMISSIONS,
                    ...PlatformAdministration::PERMISSIONS, 'points.manage-rules', ...PhysicalShop::PERMISSIONS, ...PlatformFunds::PERMISSIONS,
                    'activities.manage-checkin', 'activities.view-checkin', 'system.manage-business-timezone', 'content.manage',
                ]],
            );
            $user = new User(['username' => strtolower($username), 'name' => $name, 'password' => $password]);
            $user->forceFill(['panel_enabled' => true])->save();
            $user->roles()->attach($role);
        }, 3);
    }
}
