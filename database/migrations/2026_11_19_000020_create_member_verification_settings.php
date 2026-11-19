<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_verification_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('sms_enabled');
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('updated_at')->nullable();
        });
        DB::table('member_verification_settings')->insert(['id' => 1, 'sms_enabled' => true]);
        $this->updateRole(add: true);
    }

    public function down(): void
    {
        $this->updateRole(add: false);
        Schema::dropIfExists('member_verification_settings');
    }

    private function updateRole(bool $add): void
    {
        $role = DB::table('admin_roles')->where('name', '会员运营');
        $stored = $role->value('permissions');
        if ($stored !== null) {
            $permissions = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
            $key = 'system.manage-member-verification';
            $role->update(['permissions' => json_encode(array_values($add
                ? array_unique([...$permissions, $key])
                : array_diff($permissions, [$key])), JSON_THROW_ON_ERROR)]);
        }
    }
};
