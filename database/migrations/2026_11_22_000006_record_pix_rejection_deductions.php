<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pix_withdrawals', fn (Blueprint $table) => $table->boolean('rejection_deducted')->default(false));
    }

    public function down(): void
    {
        Schema::table('pix_withdrawals', fn (Blueprint $table) => $table->dropColumn('rejection_deducted'));
    }
};
