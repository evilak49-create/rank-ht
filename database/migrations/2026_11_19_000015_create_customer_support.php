<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = ['support.manage', 'system.manage-ai'];

    public function up(): void
    {
        Schema::create('support_faqs', function (Blueprint $table): void {
            $table->id();
            $table->string('question', 200);
            $table->json('aliases');
            $table->text('answer');
            $table->boolean('published')->default(false)->index();
            $table->foreignId('help_article_id')->nullable()->constrained('site_articles')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('support_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('ai_enabled')->default(false);
            $table->string('welcome', 400)->nullable();
            $table->json('recommended_ids');
            $table->json('channels');
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        DB::table('support_settings')->insert(['id' => 1, 'recommended_ids' => '[]', 'channels' => '[]']);
        Schema::create('ai_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('model', 64)->nullable();
            $table->text('api_key')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        DB::table('ai_settings')->insert(['id' => 1]);
        Schema::create('ai_setting_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('model', 64);
            $table->string('status', 12);
            $table->timestamp('created_at');
        });
        $this->permissions(add: true);
    }

    public function down(): void
    {
        $this->permissions(add: false);
        foreach (['ai_setting_audits', 'ai_settings', 'support_settings', 'support_faqs'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function permissions(bool $add): void
    {
        $role = DB::table('admin_roles')->where('name', '会员运营');
        $stored = $role->value('permissions');
        if ($stored !== null) {
            $permissions = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
            $role->update(['permissions' => json_encode(array_values($add
                ? array_unique([...$permissions, ...self::PERMISSIONS])
                : array_diff($permissions, self::PERMISSIONS)), JSON_THROW_ON_ERROR)]);
        }
    }
};
