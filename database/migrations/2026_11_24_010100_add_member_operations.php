<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->ipAddress('registration_ip')->nullable()->index();
            $table->char('registration_country', 2)->nullable();
            $table->char('registration_device_hash', 64)->nullable()->index();
        });
        Schema::table('member_verification_settings', function (Blueprint $table): void {
            $table->string('mode', 16)->nullable();
            $table->unsignedInteger('ip_registration_limit')->nullable();
            $table->unsignedInteger('device_registration_limit')->nullable();
        });
        Schema::create('member_cash_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('operation_key');
            $table->bigInteger('amount_cents');
            $table->unsignedBigInteger('balance_before_cents');
            $table->unsignedBigInteger('balance_after_cents');
            $table->string('reason', 300);
            $table->timestamp('created_at');
            $table->unique(['member_id', 'operation_key']);
        });
        Schema::create('platform_binding_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_binding_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform_account_id', 190)->nullable();
            $table->string('method', 12);
            $table->string('status', 12)->index();
            $table->string('failure_reason', 300)->nullable();
            $table->timestamp('callback_at')->nullable();
            $table->timestamps();
            $table->index(['member_id', 'platform_id', 'created_at']);
        });
        Schema::table('platform_registration_intents', function (Blueprint $table): void {
            $table->foreignId('platform_binding_attempt_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('platform_registration_intents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('platform_binding_attempt_id');
        });
        Schema::dropIfExists('platform_binding_attempts');
        Schema::dropIfExists('member_cash_adjustments');
        Schema::table('member_verification_settings', function (Blueprint $table): void {
            $table->dropColumn(['mode', 'ip_registration_limit', 'device_registration_limit']);
        });
        Schema::table('members', function (Blueprint $table): void {
            $table->dropIndex(['registration_ip']);
            $table->dropIndex(['registration_device_hash']);
            $table->dropColumn(['registration_ip', 'registration_country', 'registration_device_hash']);
        });
    }
};
