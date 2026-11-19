<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_bindings', function (Blueprint $table) {
            $table->unsignedBigInteger('baseline_version')->default(1);
            $table->unsignedBigInteger('point_remainder')->default(0);
            $table->timestamp('settlement_paused_at')->nullable();
        });
        Schema::table('point_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('source', 24)->default('manual')->index();
            $table->unsignedBigInteger('source_id')->nullable();
        });
        Schema::create('point_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('points_per_brl_micros');
            $table->unsignedBigInteger('wager_multiplier_micros');
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        DB::table('point_rules')->insert([
            'points_per_brl_micros' => 10000, 'wager_multiplier_micros' => 1000000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::create('point_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('platform_id')->constrained()->restrictOnDelete();
            $table->foreignId('platform_binding_id')->constrained()->restrictOnDelete();
            $table->foreignId('point_rule_id')->constrained()->restrictOnDelete();
            $table->foreignId('point_entry_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('baseline_version');
            $table->unsignedBigInteger('estimated_points');
            $table->string('eligibility', 24)->index();
            $table->json('snapshot');
            $table->timestamp('expires_at')->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_quotes');
        Schema::dropIfExists('point_rules');
        Schema::table('point_entries', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'source_id']);
        });
        Schema::table('platform_bindings', function (Blueprint $table) {
            $table->dropColumn(['baseline_version', 'point_remainder', 'settlement_paused_at']);
        });
    }
};
