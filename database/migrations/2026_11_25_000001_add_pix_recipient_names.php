<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', fn (Blueprint $table) => $table->text('pix_recipient_name')->nullable());
        Schema::table('pix_withdrawals', fn (Blueprint $table) => $table->text('recipient_name_snapshot')->nullable());
    }

    public function down(): void
    {
        Schema::table('pix_withdrawals', fn (Blueprint $table) => $table->dropColumn('recipient_name_snapshot'));
        Schema::table('members', fn (Blueprint $table) => $table->dropColumn('pix_recipient_name'));
    }
};
