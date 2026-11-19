<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = ['protection.claims.view', 'protection.terms.manage', 'protection.eligibility.manage'];

    public function up(): void
    {
        Schema::table('platform_bindings', function (Blueprint $table): void {
            $table->string('protection_status', 16)->default('pending');
        });
        Schema::create('protection_terms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('title', 160);
            $table->text('body');
            $table->timestamps();
            $table->index(['platform_id', 'id']);
        });
        Schema::create('protection_claims', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id');
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('platform_binding_id')->constrained()->restrictOnDelete();
            $table->foreignId('platform_id')->constrained()->restrictOnDelete();
            $table->foreignId('protection_term_id')->nullable()->constrained('protection_terms')->restrictOnDelete();
            $table->string('platform_name');
            $table->string('platform_account_id');
            $table->string('protection_status', 16);
            $table->unsignedBigInteger('amount_cents');
            $table->dateTime('withdrawn_at');
            $table->text('description');
            $table->timestamps();
            $table->unique(['member_id', 'request_id']);
            $table->index(['member_id', 'id']);
        });
        Schema::create('claim_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('protection_claim_id')->constrained()->restrictOnDelete();
            $table->string('disk', 32);
            $table->string('object_key')->unique();
            $table->string('mime', 32);
            $table->unsignedInteger('size_bytes');
            $table->unsignedTinyInteger('position');
            $table->unique(['protection_claim_id', 'position']);
        });
        $this->updatePermissions(add: true);
    }

    public function down(): void
    {
        $this->updatePermissions(add: false);
        Schema::dropIfExists('claim_evidence');
        Schema::dropIfExists('protection_claims');
        Schema::dropIfExists('protection_terms');
        Schema::table('platform_bindings', fn (Blueprint $table) => $table->dropColumn('protection_status'));
    }

    private function updatePermissions(bool $add): void
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
