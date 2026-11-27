<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateOperatorRole(add: true);

        Schema::create('promotion_channels', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->text('notes')->nullable();
            $table->string('code', 32)->unique();
            $table->timestamps();
        });
        Schema::table('members', function (Blueprint $table): void {
            $table->foreignId('promotion_channel_id')->nullable()->constrained()->restrictOnDelete();
            $table->index(['promotion_channel_id', 'created_at']);
        });
        Schema::create('promotion_visits', function (Blueprint $table): void {
            $table->foreignId('promotion_channel_id')->constrained();
            $table->date('business_date');
            $table->uuid('visitor_id');
            $table->primary(['promotion_channel_id', 'business_date', 'visitor_id']);
        });
        Schema::create('promotion_expenses', function (Blueprint $table): void {
            $table->foreignId('promotion_channel_id')->constrained();
            $table->date('business_date');
            $table->unsignedBigInteger('amount_cents');
            $table->primary(['promotion_channel_id', 'business_date']);
        });
    }

    public function down(): void
    {
        $this->updateOperatorRole(add: false);
        Schema::dropIfExists('promotion_expenses');
        Schema::dropIfExists('promotion_visits');
        Schema::table('members', function (Blueprint $table): void {
            $table->dropIndex(['promotion_channel_id', 'created_at']);
            $table->dropConstrainedForeignId('promotion_channel_id');
        });
        Schema::dropIfExists('promotion_channels');
    }

    private function updateOperatorRole(bool $add): void
    {
        $role = DB::table('admin_roles')->where('name', '会员运营')->first();
        if ($role === null) {
            return;
        }

        $permissions = json_decode($role->permissions, true, flags: JSON_THROW_ON_ERROR);
        $permission = 'members.manage-promotion-channels';
        if ($add && in_array('system.manage-roles', $permissions, true)) {
            $permissions = array_values(array_unique([...$permissions, $permission]));
        } elseif (! $add) {
            $permissions = array_values(array_diff($permissions, [$permission]));
        }

        DB::table('admin_roles')->where('id', $role->id)
            ->update(['permissions' => json_encode($permissions, JSON_THROW_ON_ERROR)]);
    }
};
