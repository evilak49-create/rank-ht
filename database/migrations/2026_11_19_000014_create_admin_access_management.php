<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = ['system.manage-roles', 'system.manage-admins', 'system.view-admin-audit'];

    public function up(): void
    {
        Schema::create('admin_access_lock', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('admin_access_lock')->insert(['id' => 1]);
        Schema::create('admin_access_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('admin_role_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('action', 40);
            $table->json('details');
            $table->timestamp('created_at')->index();
        });
        $this->updateDefaultRole(add: true);
    }

    public function down(): void
    {
        $this->updateDefaultRole(add: false);
        Schema::dropIfExists('admin_access_audits');
        Schema::dropIfExists('admin_access_lock');
    }

    private function updateDefaultRole(bool $add): void
    {
        $role = DB::table('admin_roles')->where('name', '会员运营');
        $stored = $role->value('permissions');
        if ($stored !== null) {
            $permissions = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
            $role->update(['permissions' => json_encode(array_values($add
                ? array_unique([...$permissions, ...self::PERMISSIONS])
                : array_diff($permissions, self::PERMISSIONS)), JSON_THROW_ON_ERROR)]);
        }
    }
};
