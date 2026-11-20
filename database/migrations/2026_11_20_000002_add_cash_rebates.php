<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->unsignedBigInteger('available_balance_cents')->default(0);
            $table->unsignedBigInteger('held_balance_cents')->default(0);
        });
        Schema::table('point_rules', function (Blueprint $table): void {
            $table->unsignedBigInteger('rebate_percent_micros')->default(0);
            $table->unsignedBigInteger('rebate_wager_multiplier_micros')->nullable();
        });
        Schema::table('point_quotes', function (Blueprint $table): void {
            $table->unsignedBigInteger('rebate_cents')->default(0);
        });
        Schema::table('platform_bindings', function (Blueprint $table): void {
            $table->unsignedBigInteger('rebate_remainder')->default(0);
        });
        Schema::table('pix_withdrawals', function (Blueprint $table): void {
            $table->dropForeign(['red_packet_round_id']);
            $table->unsignedBigInteger('red_packet_round_id')->nullable()->change();
        });
        Schema::table('pix_withdrawals', function (Blueprint $table): void {
            $table->foreign('red_packet_round_id')->references('id')->on('red_packet_rounds')->restrictOnDelete();
            $table->string('manual_transfer_reference', 190)->nullable()->unique();
        });
        Schema::create('cash_balance_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->string('source', 24);
            $table->unsignedBigInteger('source_id');
            $table->bigInteger('amount_cents');
            $table->unsignedBigInteger('balance_after_cents');
            $table->timestamp('created_at');
            $table->unique(['source', 'source_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('cash_balance_entries')->exists() || DB::table('pix_withdrawals')->whereNull('red_packet_round_id')->exists()) {
            throw new RuntimeException('Cash rebate history exists. Preserve it before rolling back.');
        }

        Schema::dropIfExists('cash_balance_entries');
        Schema::table('pix_withdrawals', function (Blueprint $table): void {
            $table->dropForeign(['red_packet_round_id']);
            $table->unsignedBigInteger('red_packet_round_id')->change();
            $table->dropUnique(['manual_transfer_reference']);
            $table->dropColumn('manual_transfer_reference');
        });
        Schema::table('pix_withdrawals', function (Blueprint $table): void {
            $table->foreign('red_packet_round_id')->references('id')->on('red_packet_rounds')->restrictOnDelete();
        });
        Schema::table('platform_bindings', fn (Blueprint $table) => $table->dropColumn('rebate_remainder'));
        Schema::table('point_quotes', fn (Blueprint $table) => $table->dropColumn('rebate_cents'));
        Schema::table('point_rules', fn (Blueprint $table) => $table->dropColumn(['rebate_percent_micros', 'rebate_wager_multiplier_micros']));
        Schema::table('members', fn (Blueprint $table) => $table->dropColumn(['available_balance_cents', 'held_balance_cents']));
    }
};
