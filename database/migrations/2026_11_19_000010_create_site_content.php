<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_articles', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('title', 150);
            $table->text('body');
            $table->json('steps')->nullable();
            $table->string('target_type', 24)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->index(['type', 'published', 'published_at']);
        });

        Schema::create('site_banners', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('image_url', 2048);
            $table->string('target_type', 24);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(false);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->index(['active', 'sort_order']);
        });
        $this->updateDefaultRole(add: true);
    }

    public function down(): void
    {
        $this->updateDefaultRole(add: false);
        Schema::dropIfExists('site_banners');
        Schema::dropIfExists('site_articles');
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
            ? array_unique([...$permissions, 'content.manage'])
            : array_diff($permissions, ['content.manage'])), JSON_THROW_ON_ERROR)]);
    }
};
