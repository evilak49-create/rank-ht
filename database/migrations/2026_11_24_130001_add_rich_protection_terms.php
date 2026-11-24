<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('protection_terms', function (Blueprint $table): void {
            $table->mediumText('body_html')->nullable();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (DB::table('protection_terms')->whereNotNull('body_html')->orWhereNotNull('deleted_at')->exists()) {
            throw new RuntimeException('已有富文本或已下架的保障条款，无法回滚。');
        }

        Schema::table('protection_terms', fn (Blueprint $table) => $table->dropColumn(['body_html', 'deleted_at']));
    }
};
