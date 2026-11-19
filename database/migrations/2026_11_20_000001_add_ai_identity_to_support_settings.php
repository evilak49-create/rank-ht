<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_settings', function (Blueprint $table): void {
            $table->string('display_name', 80)->nullable();
            $table->string('description', 200)->nullable();
            $table->string('avatar_url', 2048)->nullable();
            $table->text('system_prompt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('support_settings', function (Blueprint $table): void {
            $table->dropColumn(['display_name', 'description', 'avatar_url', 'system_prompt']);
        });
    }
};
