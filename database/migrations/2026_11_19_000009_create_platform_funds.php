<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = ['funds.view', 'funds.review', 'funds.resume'];

    public function up(): void
    {
        Schema::create('fund_rate_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('cents_per_point');
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        DB::table('fund_rate_versions')->insert(['cents_per_point' => 100, 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('platform_fund_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('order_number')->unique();
            $table->uuid('request_id');
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('platform_id')->constrained()->restrictOnDelete();
            $table->foreignId('platform_binding_id')->constrained()->restrictOnDelete();
            $table->foreignId('fund_rate_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('platform_account_id', 40);
            $table->unsignedInteger('points');
            $table->unsignedInteger('cents_per_point');
            $table->unsignedBigInteger('amount_cents');
            $table->string('status', 16)->index();
            $table->string('review_reason', 300)->nullable();
            $table->string('channel_reference', 190)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['member_id', 'request_id']);
        });
        Schema::create('platform_fund_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_fund_order_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('send_started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('needs_review_at')->nullable();
            $table->string('pause_reason', 24)->nullable();
            $table->uuid('lease_key')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamps();
        });
        Schema::create('platform_fund_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_fund_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event', 32);
            $table->timestamp('created_at');
        });
        $this->updateDefaultRole(add: true);
    }

    public function down(): void
    {
        $this->updateDefaultRole(add: false);
        Schema::dropIfExists('platform_fund_events');
        Schema::dropIfExists('platform_fund_tasks');
        Schema::dropIfExists('platform_fund_orders');
        Schema::dropIfExists('fund_rate_versions');
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
