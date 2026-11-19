<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PLATFORM_PERMISSIONS = [
        'platforms.view', 'platforms.edit', 'platforms.configure-query', 'platforms.test-query', 'platforms.change-status',
    ];

    public function up(): void
    {
        Schema::create('platforms', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('promotion_url', 2048);
            $table->string('logo_url', 2048)->nullable();
            $table->text('description')->nullable();
            $table->decimal('rating', 2, 1);
            $table->unsignedTinyInteger('featured_position')->nullable()->unique();
            $table->boolean('manually_disabled')->default(false);
            $table->longText('draft_query')->nullable();
            $table->longText('active_query')->nullable();
            $table->unsignedBigInteger('query_revision')->default(0);
            $table->unsignedBigInteger('active_query_revision')->nullable();
            $table->unsignedTinyInteger('query_failures')->default(0);
            $table->string('query_health_reason')->nullable();
            $table->timestamp('query_unhealthy_at')->nullable();
            $table->timestamp('query_tested_at')->nullable();
            $table->string('last_query_result')->nullable();
            $table->timestamps();
        });

        Schema::create('platform_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action');
            $table->unsignedBigInteger('query_revision')->nullable();
            $table->string('result')->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('platform_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('platform_id')->constrained()->restrictOnDelete();
            $table->string('platform_account_id', 40);
            $table->string('query_phone', 11);
            $table->unsignedBigInteger('baseline_deposit_cents');
            $table->unsignedBigInteger('baseline_valid_bet_cents');
            $table->timestamp('baseline_observed_at');
            $table->timestamps();
            $table->unique(['member_id', 'platform_id']);
            $table->unique(['platform_id', 'platform_account_id']);
        });

        $this->updateDefaultRole(add: true);
    }

    public function down(): void
    {
        $this->updateDefaultRole(add: false);
        Schema::dropIfExists('platform_bindings');
        Schema::dropIfExists('platform_audits');
        Schema::dropIfExists('platforms');
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
            ? array_unique([...$permissions, ...self::PLATFORM_PERMISSIONS])
            : array_diff($permissions, self::PLATFORM_PERMISSIONS)), JSON_THROW_ON_ERROR)]);
    }
};
