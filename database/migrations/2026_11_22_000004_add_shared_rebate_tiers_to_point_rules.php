<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_rules', fn (Blueprint $table) => $table->json('rebate_tiers')->nullable());
    }

    public function down(): void
    {
        Schema::table('point_rules', fn (Blueprint $table) => $table->dropColumn('rebate_tiers'));
    }
};
