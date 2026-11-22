<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_recharges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_binding_id')->constrained()->restrictOnDelete();
            $table->string('network', 16);
            $table->string('external_id', 128);
            $table->unsignedBigInteger('amount_cents');
            $table->timestamp('recharged_at');
            $table->timestamps();
            $table->unique(['platform_binding_id', 'network', 'external_id'], 'platform_recharges_provider_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_recharges');
    }
};
