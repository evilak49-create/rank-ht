<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_quotes', function (Blueprint $table): void {
            $table->date('dispatch_day')->nullable();
            $table->unique(['platform_binding_id', 'dispatch_day'], 'point_quotes_binding_dispatch_unique');
            $table->index(['member_id', 'dispatch_day'], 'point_quotes_member_dispatch_index');
        });
    }

    public function down(): void
    {
        Schema::table('point_quotes', function (Blueprint $table): void {
            $table->dropUnique('point_quotes_binding_dispatch_unique');
            $table->dropIndex('point_quotes_member_dispatch_index');
            $table->dropColumn('dispatch_day');
        });
    }
};
