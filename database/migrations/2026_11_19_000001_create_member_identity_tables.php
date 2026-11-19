<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 11)->unique();
            $table->string('password');
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->unsignedBigInteger('session_version')->default(0);
            $table->timestamps();
        });

        Schema::create('sms_challenges', function (Blueprint $table) {
            $table->string('phone', 11);
            $table->string('purpose', 32);
            $table->string('digest', 64);
            $table->timestamp('expires_at');
            $table->primary(['phone', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_challenges');
        Schema::dropIfExists('members');
    }
};
