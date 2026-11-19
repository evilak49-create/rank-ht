<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oss_locations', function (Blueprint $table): void {
            $table->id();
            $table->string('purpose', 8);
            $table->string('provider', 8);
            $table->string('bucket', 63);
            $table->string('region', 32);
            $table->string('endpoint', 512)->nullable();
            $table->string('public_url', 512)->nullable();
            $table->text('credentials');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->index(['provider', 'bucket', 'region', 'purpose']);
        });
        Schema::create('oss_settings', function (Blueprint $table): void {
            $table->string('purpose', 8)->primary();
            $table->foreignId('oss_location_id')->nullable()->constrained()->restrictOnDelete();
        });
        DB::table('oss_settings')->insert([['purpose' => 'public'], ['purpose' => 'private']]);
        Schema::create('oss_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('oss_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('purpose', 8);
            $table->string('provider', 8);
            $table->string('status', 16);
            $table->string('error', 40)->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('oss_public_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('oss_location_id')->constrained()->restrictOnDelete();
            $table->string('object_key')->unique();
            $table->string('url', 800);
            $table->string('mime', 32);
            $table->timestamp('created_at');
        });
        $this->updatePermissions(add: true);
    }

    public function down(): void
    {
        $this->updatePermissions(add: false);
        foreach (['oss_public_images', 'oss_audits', 'oss_settings', 'oss_locations'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function updatePermissions(bool $add): void
    {
        $role = DB::table('admin_roles')->where('name', '会员运营');
        $stored = $role->value('permissions');
        if ($stored !== null) {
            $permissions = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
            $role->update(['permissions' => json_encode(array_values($add
                ? array_unique([...$permissions, 'system.manage-oss'])
                : array_diff($permissions, ['system.manage-oss'])), JSON_THROW_ON_ERROR)]);
        }
    }
};
