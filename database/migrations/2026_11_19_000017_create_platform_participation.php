<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = ['platforms.view-reviews', 'platforms.moderate-reviews'];

    public function up(): void
    {
        Schema::create('platform_favorites', function (Blueprint $table): void {
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->primary(['member_id', 'platform_id']);
            $table->index(['member_id', 'created_at']);
        });
        Schema::create('platform_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_id')->constrained()->restrictOnDelete();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('request_id');
            $table->string('display_name', 32);
            $table->unsignedTinyInteger('rating');
            $table->text('body');
            $table->string('status', 16);
            $table->string('review_reason', 300)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['member_id', 'request_id']);
            $table->index(['platform_id', 'status', 'published_at']);
            $table->index(['member_id', 'platform_id', 'id']);
            $table->index(['status', 'id']);
        });
        $this->updateRole(add: true);
    }

    public function down(): void
    {
        $this->updateRole(add: false);
        Schema::dropIfExists('platform_reviews');
        Schema::dropIfExists('platform_favorites');
    }

    private function updateRole(bool $add): void
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
