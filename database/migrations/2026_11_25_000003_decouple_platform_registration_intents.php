<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_registration_intents', function (Blueprint $table): void {
            $table->unsignedBigInteger('session_version')->nullable()->change();
            $table->timestamp('revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        DB::table('platform_registration_intents')->whereNull('session_version')->update(['session_version' => 0]);
        Schema::table('platform_registration_intents', function (Blueprint $table): void {
            $table->unsignedBigInteger('session_version')->nullable(false)->change();
            $table->dropColumn('revoked_at');
        });
    }
};
