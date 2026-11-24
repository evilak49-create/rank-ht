<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('red_packet_rules', fn (Blueprint $table) => $table->json('ratio_bps')->nullable());
        Schema::table('checkin_rule_versions', fn (Blueprint $table) => $table->boolean('cash_rewards')->default(false));
        Schema::table('member_checkins', function (Blueprint $table): void {
            $table->unsignedBigInteger('reward_cents')->default(0);
            $table->unsignedBigInteger('cash_balance_entry_id')->nullable();
        });
        Schema::table('protection_claims', function (Blueprint $table): void {
            $table->string('case_status', 16)->default('pending')->index();
        });
        Schema::table('site_banners', fn (Blueprint $table) => $table->string('target_path', 1024)->nullable());
        Schema::table('site_articles', fn (Blueprint $table) => $table->text('body_html')->nullable());
    }

    public function down(): void
    {
        Schema::table('red_packet_rules', fn (Blueprint $table) => $table->dropColumn('ratio_bps'));
        Schema::table('checkin_rule_versions', fn (Blueprint $table) => $table->dropColumn('cash_rewards'));
        Schema::table('member_checkins', fn (Blueprint $table) => $table->dropColumn(['reward_cents', 'cash_balance_entry_id']));
        Schema::table('protection_claims', function (Blueprint $table): void {
            $table->dropIndex(['case_status']);
            $table->dropColumn('case_status');
        });
        Schema::table('site_banners', fn (Blueprint $table) => $table->dropColumn('target_path'));
        Schema::table('site_articles', fn (Blueprint $table) => $table->dropColumn('body_html'));
    }
};
