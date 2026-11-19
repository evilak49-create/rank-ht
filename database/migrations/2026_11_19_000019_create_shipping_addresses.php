<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_addresses', function (Blueprint $table): void {
            $table->foreignId('member_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('version');
            $table->text('address');
            $table->timestamps();
        });
        Schema::table('shop_orders', fn (Blueprint $table) => $table->unsignedBigInteger('address_version')->nullable());
    }

    public function down(): void
    {
        Schema::table('shop_orders', fn (Blueprint $table) => $table->dropColumn('address_version'));
        Schema::dropIfExists('shipping_addresses');
    }
};
