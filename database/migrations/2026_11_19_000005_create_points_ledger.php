<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = ['members.change-phone', 'members.reset-password', 'members.adjust-points', 'points.view'];

    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->unsignedBigInteger('points_balance')->default(0);
        });

        Schema::table('admin_audits', function (Blueprint $table) {
            $table->json('details')->nullable();
        });

        Schema::create('point_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('operation_key');
            $table->string('direction', 8);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('balance_before');
            $table->unsignedBigInteger('balance_after');
            $table->string('reason', 300);
            $table->timestamps();
            $table->unique(['member_id', 'operation_key']);
        });

        $this->updateDefaultRole(add: true);
    }

    public function down(): void
    {
        $this->updateDefaultRole(add: false);
        Schema::dropIfExists('point_entries');
        Schema::table('admin_audits', fn (Blueprint $table) => $table->dropColumn('details'));
        Schema::table('members', fn (Blueprint $table) => $table->dropColumn('points_balance'));
    }

    private function updateDefaultRole(bool $add): void
    {
        $role = DB::table('admin_roles')->where('name', '会员运营');
        $stored = $role->value('permissions');
        if ($stored === null) {
            return;
        }

        $permissions = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        $role->update(['permissions' => json_encode(array_values($add
            ? array_unique([...$permissions, ...self::PERMISSIONS])
            : array_diff($permissions, self::PERMISSIONS)), JSON_THROW_ON_ERROR)]);
    }
};
