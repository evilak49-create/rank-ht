<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pix_withdrawals', function (Blueprint $table): void {
            $table->uuid('payout_number')->nullable()->unique();
            $table->timestamp('callback_received_at')->nullable();
        });

        Schema::table('pix_payout_channels', function (Blueprint $table): void {
            $table->bigInteger('last_balance_cents')->nullable();
            $table->timestamp('last_balance_at')->nullable();
            $table->timestamp('last_balance_attempt_at')->nullable();
            $table->boolean('last_balance_failed')->default(false);
        });

        Schema::table('platform_fund_orders', fn (Blueprint $table) => $table->boolean('rejection_deducted')->default(false));
    }

    public function down(): void
    {
        if (DB::table('pix_withdrawals')->whereNotNull('payout_number')->exists()
            || DB::table('platform_fund_orders')->where('rejection_deducted', true)->exists()) {
            throw new RuntimeException('已有新出款或兑换扣款记录，无法安全回滚。');
        }

        Schema::table('platform_fund_orders', fn (Blueprint $table) => $table->dropColumn('rejection_deducted'));
        Schema::table('pix_payout_channels', fn (Blueprint $table) => $table->dropColumn([
            'last_balance_cents', 'last_balance_at', 'last_balance_attempt_at', 'last_balance_failed',
        ]));
        Schema::table('pix_withdrawals', function (Blueprint $table): void {
            $table->dropUnique(['payout_number']);
            $table->dropColumn(['payout_number', 'callback_received_at']);
        });
    }
};
