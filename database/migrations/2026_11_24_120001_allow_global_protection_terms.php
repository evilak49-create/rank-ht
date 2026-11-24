<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->changePlatformNullability(true);
    }

    public function down(): void
    {
        if (DB::table('protection_terms')->whereNull('platform_id')->exists()) {
            throw new RuntimeException('已有全站保障条款，无法回滚。');
        }

        $this->changePlatformNullability(false);
    }

    private function changePlatformNullability(bool $nullable): void
    {
        $mysql = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
        if ($mysql && Schema::hasForeignKey('protection_terms', 'protection_terms_platform_id_foreign')) {
            Schema::table('protection_terms', fn (Blueprint $table) => $table->dropForeign(['platform_id']));
        }

        Schema::table('protection_terms', fn (Blueprint $table) => $table->foreignId('platform_id')->nullable($nullable)->change());

        if ($mysql) {
            Schema::table('protection_terms', fn (Blueprint $table) => $table
                ->foreign('platform_id')->references('id')->on('platforms')->restrictOnDelete());
        }
    }
};
