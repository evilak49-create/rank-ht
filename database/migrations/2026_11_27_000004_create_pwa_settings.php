<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSION = 'system.manage-pwa';

    public function up(): void
    {
        Schema::create('pwa_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->text('settings');
            $table->longText('icon')->nullable();
        });

        $role = DB::table('admin_roles')->where('name', '会员运营')
            ->whereJsonContains('permissions', 'system.manage-roles')
            ->whereJsonContains('permissions', 'system.manage-admins');
        $stored = $role->value('permissions');
        if ($stored !== null) {
            $permissions = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
            $role->update(['permissions' => json_encode(array_values(
                array_unique([...$permissions, self::PERMISSION])
            ), JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        $roles = DB::table('admin_roles')->whereJsonContains('permissions', self::PERMISSION)->get(['id', 'permissions']);
        foreach ($roles as $role) {
            $permissions = json_decode($role->permissions, true, flags: JSON_THROW_ON_ERROR);
            DB::table('admin_roles')->where('id', $role->id)->update(['permissions' => json_encode(
                array_values(array_diff($permissions, [self::PERMISSION])), JSON_THROW_ON_ERROR
            )]);
        }
        Schema::dropIfExists('pwa_settings');
    }
};
