<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = ['points.manage-rules', 'shop.manage-products', 'shop.view-orders', 'shop.ship-orders', 'shop.cancel-orders'];

    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->unsignedInteger('points_price');
            $table->unsignedInteger('stock')->default(0);
            $table->boolean('is_active')->default(false)->index();
            $table->timestamps();
        });
        Schema::create('shop_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('order_number')->unique();
            $table->uuid('request_id');
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('product_name', 120);
            $table->unsignedInteger('points_price');
            $table->json('address');
            $table->string('status', 16)->index();
            $table->string('carrier', 120)->nullable();
            $table->string('tracking_number', 120)->nullable();
            $table->string('cancellation_reason', 300)->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->unique(['member_id', 'request_id']);
        });
        Schema::create('product_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('shop_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->integer('delta');
            $table->unsignedInteger('stock_after');
            $table->string('reason', 300);
            $table->timestamp('created_at');
        });
        $this->updateDefaultRole(add: true);
    }

    public function down(): void
    {
        $this->updateDefaultRole(add: false);
        Schema::dropIfExists('product_stock_movements');
        Schema::dropIfExists('shop_orders');
        Schema::dropIfExists('products');
    }

    private function updateDefaultRole(bool $add): void
    {
        $role = DB::table('admin_roles')->where('name', '会员运营');
        $stored = $role->value('permissions');
        if ($stored === null) {
            return;
        }

        $permissions = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        $role->update(['permissions' => json_encode(array_values($add
            ? array_unique([...$permissions, ...self::PERMISSIONS])
            : array_diff($permissions, self::PERMISSIONS)), JSON_THROW_ON_ERROR)]);
    }
};
