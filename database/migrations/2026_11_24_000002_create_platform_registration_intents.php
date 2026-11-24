<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_registration_intents', function (Blueprint $table): void {
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('session_version');
            $table->timestamp('expires_at');
            $table->primary(['member_id', 'platform_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_registration_intents');
    }
};
