<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->unsignedBigInteger('price_cents')->nullable());
        Schema::table('shop_orders', fn (Blueprint $table) => $table->unsignedBigInteger('balance_paid_cents')->nullable());
        Schema::table('platform_fund_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('balance_paid_cents')->nullable();
            $table->unsignedBigInteger('fund_rate_version_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('shop_orders')->whereNotNull('balance_paid_cents')->exists()
            || DB::table('platform_fund_orders')->whereNotNull('balance_paid_cents')->exists()
            || DB::table('products')->whereNotNull('price_cents')->exists()) {
            throw new RuntimeException('商城已使用余额定价或结算，无法回滚此迁移。');
        }
        Schema::table('platform_fund_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('fund_rate_version_id')->nullable(false)->change();
            $table->dropColumn('balance_paid_cents');
        });
        Schema::table('shop_orders', fn (Blueprint $table) => $table->dropColumn('balance_paid_cents'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('price_cents'));
    }
};
