<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platforms', function (Blueprint $table): void {
            $table->decimal('guarantee_amount_brl', 12, 2)->nullable();
            $table->decimal('max_rebate_percent', 9, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platforms', function (Blueprint $table): void {
            $table->dropColumn(['guarantee_amount_brl', 'max_rebate_percent']);
        });
    }
};
