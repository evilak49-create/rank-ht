<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pix_payout_channels', function (Blueprint $table): void {
            $table->string('driver', 24)->default('manual');
            $table->longText('configuration')->nullable();
        });
        Schema::table('pix_withdrawals', function (Blueprint $table): void {
            $table->longText('payout_configuration')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('pix_withdrawals')->whereNotNull('payout_configuration')->exists()) {
            throw new RuntimeException('已有三方出款快照，无法回滚。');
        }
        Schema::table('pix_withdrawals', fn (Blueprint $table) => $table->dropColumn('payout_configuration'));
        Schema::table('pix_payout_channels', fn (Blueprint $table) => $table->dropColumn(['driver', 'configuration']));
    }
};
