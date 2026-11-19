<?php

namespace App\Auth;

use App\Models\AdminRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdminAccountCreator
{
    public function create(string $username, string $name, string $password): void
    {
        DB::transaction(function () use ($username, $name, $password): void {
            $role = AdminRole::query()->firstOrCreate(
                ['name' => '会员运营'],
                ['permissions' => ['members.view', 'members.change-status']],
            );
            $user = new User(['username' => strtolower($username), 'name' => $name, 'password' => $password]);
            $user->forceFill(['panel_enabled' => true])->save();
            $user->roles()->attach($role);
        }, 3);
    }
}
