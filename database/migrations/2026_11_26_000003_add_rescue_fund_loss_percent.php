<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rescue_fund_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('loss_percent_micros')->default(100_000_000);
        });
    }

    public function down(): void
    {
        Schema::table('rescue_fund_settings', fn (Blueprint $table) => $table->dropColumn('loss_percent_micros'));
    }
};
