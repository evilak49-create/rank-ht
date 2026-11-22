<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platforms', function (Blueprint $table): void {
            $table->decimal('minimum_deposit_brl', 12, 2)->nullable();
            $table->decimal('minimum_withdrawal_brl', 12, 2)->nullable();
            $table->string('withdrawal_time', 120)->nullable();
            $table->string('support_url', 2048)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platforms', function (Blueprint $table): void {
            $table->dropColumn(['minimum_deposit_brl', 'minimum_withdrawal_brl', 'withdrawal_time', 'support_url']);
        });
    }
};
