<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_review_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_id');
            $table->unsignedTinyInteger('quantity');
            $table->json('facts');
            $table->string('status', 12);
            $table->string('model', 64)->nullable();
            $table->string('failure_code', 30)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->uuid('lease_key')->nullable();
            $table->dateTime('lease_until')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['platform_id', 'request_id']);
            $table->index(['status', 'lease_until']);
        });
        Schema::table('platform_reviews', function (Blueprint $table): void {
            $table->unsignedBigInteger('member_id')->nullable()->change();
            $table->unsignedTinyInteger('rating')->nullable()->change();
            $table->string('source', 8)->default('member');
            $table->foreignId('platform_review_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('batch_position')->nullable();
            $table->unique(['platform_review_batch_id', 'batch_position'], 'platform_review_batch_slots');
        });
        $this->updateRole(add: true);
    }

    public function down(): void
    {
        $this->updateRole(add: false);
        DB::table('platform_reviews')->where('source', 'ai')->delete();
        Schema::table('platform_reviews', function (Blueprint $table): void {
            $table->dropUnique('platform_review_batch_slots');
            $table->dropConstrainedForeignId('platform_review_batch_id');
            $table->dropColumn(['source', 'batch_position']);
            $table->unsignedBigInteger('member_id')->nullable(false)->change();
            $table->unsignedTinyInteger('rating')->nullable(false)->change();
        });
        Schema::dropIfExists('platform_review_batches');
    }

    private function updateRole(bool $add): void
    {
        $role = DB::table('admin_roles')->where('name', '会员运营');
        $stored = $role->value('permissions');
        if ($stored !== null) {
            $permissions = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
            $role->update(['permissions' => json_encode(array_values($add
                ? array_unique([...$permissions, 'platforms.generate-reviews'])
                : array_diff($permissions, ['platforms.generate-reviews'])), JSON_THROW_ON_ERROR)]);
        }
    }
};
