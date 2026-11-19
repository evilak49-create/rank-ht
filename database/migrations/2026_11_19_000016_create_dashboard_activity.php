<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_visitors', function (Blueprint $table): void {
            $table->date('business_date');
            $table->char('visitor_hash', 64);
            $table->primary(['business_date', 'visitor_hash']);
        });
        Schema::create('visitor_activity', function (Blueprint $table): void {
            $table->char('visitor_hash', 64)->primary();
            $table->dateTime('last_seen_at')->index();
        });
        Schema::table('members', fn (Blueprint $table) => $table->index('created_at'));
        Schema::table('pix_withdrawals', fn (Blueprint $table) => $table->index(['status', 'paid_at']));
    }

    public function down(): void
    {
        Schema::table('pix_withdrawals', fn (Blueprint $table) => $table->dropIndex(['status', 'paid_at']));
        Schema::table('members', fn (Blueprint $table) => $table->dropIndex(['created_at']));
        Schema::dropIfExists('visitor_activity');
        Schema::dropIfExists('daily_visitors');
    }
};
