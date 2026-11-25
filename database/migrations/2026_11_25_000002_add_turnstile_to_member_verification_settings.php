<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_verification_settings', function (Blueprint $table): void {
            $table->string('turnstile_site_key', 255)->nullable();
            $table->text('turnstile_secret')->nullable();
            $table->string('turnstile_hostname', 253)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('member_verification_settings', function (Blueprint $table): void {
            $table->dropColumn(['turnstile_site_key', 'turnstile_secret', 'turnstile_hostname']);
        });
    }
};
