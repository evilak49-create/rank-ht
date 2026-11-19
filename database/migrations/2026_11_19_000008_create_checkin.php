<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = ['activities.manage-checkin', 'activities.view-checkin', 'system.manage-business-timezone'];

    public function up(): void
    {
        Schema::create('business_timezones', function (Blueprint $table) {
            $table->id();
            $table->string('timezone', 64);
            $table->timestamp('effective_at')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        DB::table('business_timezones')->insert([
            'timezone' => 'America/Sao_Paulo', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Schema::create('checkin_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->json('tiers');
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        DB::table('checkin_rule_versions')->insert(['tiers' => '[]', 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('member_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('checkin_rule_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('point_entry_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->date('business_date');
            $table->string('timezone', 64);
            $table->date('round_started_on');
            $table->unsignedInteger('streak');
            $table->unsignedInteger('reward_points');
            $table->boolean('completed_round');
            $table->timestamps();
            $table->unique(['member_id', 'business_date']);
        });
        $this->updateDefaultRole(add: true);
    }

    public function down(): void
    {
        $this->updateDefaultRole(add: false);
        Schema::dropIfExists('member_checkins');
        Schema::dropIfExists('checkin_rule_versions');
        Schema::dropIfExists('business_timezones');
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
