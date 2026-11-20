<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platforms', fn (Blueprint $table) => $table->string('network', 32)->default('panda'));
    }

    public function down(): void
    {
        Schema::table('platforms', fn (Blueprint $table) => $table->dropColumn('network'));
    }
};
