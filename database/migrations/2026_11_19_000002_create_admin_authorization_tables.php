<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('panel_enabled')->default(false);
        });

        Schema::create('admin_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->json('permissions');
            $table->timestamps();
        });

        Schema::create('admin_role_user', function (Blueprint $table) {
            $table->foreignId('admin_role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['admin_role_id', 'user_id']);
        });

        Schema::create('admin_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->string('action');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audits');
        Schema::dropIfExists('admin_role_user');
        Schema::dropIfExists('admin_roles');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('panel_enabled'));
    }
};
