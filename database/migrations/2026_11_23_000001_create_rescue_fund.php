<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_bindings', function (Blueprint $table): void {
            $table->json('rescue_baseline')->nullable();
        });

        Schema::create('rescue_fund_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('max_wager_tier_micros')->default(0);
            $table->unsignedBigInteger('percent_micros')->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('updated_at');
        });
        DB::table('rescue_fund_settings')->insert(['id' => 1, 'updated_at' => now()]);

        Schema::create('rescue_fund_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('platform_id')->constrained()->restrictOnDelete();
            $table->foreignId('platform_binding_id')->constrained()->restrictOnDelete();
            $table->string('platform_name', 120);
            $table->uuid('request_id');
            $table->unsignedBigInteger('amount_cents');
            $table->json('calculation');
            $table->timestamp('credited_at');
            $table->unique(['member_id', 'request_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('rescue_fund_claims')->exists()) {
            throw new RuntimeException('救援金已有入账流水，不能回滚领取记录。');
        }

        Schema::dropIfExists('rescue_fund_claims');
        Schema::dropIfExists('rescue_fund_settings');
        Schema::table('platform_bindings', fn (Blueprint $table) => $table->dropColumn('rescue_baseline'));
    }
};
