<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = ['system.manage-domains', 'platforms.delete'];

    public function up(): void
    {
        Schema::create('site_domains', function (Blueprint $table): void {
            $table->id();
            $table->string('host', 253)->unique();
            $table->string('purpose', 12)->index();
            $table->timestamps();
        });
        Schema::table('promotion_channels', fn (Blueprint $table) => $table->string('promotion_host', 253)->nullable());
        Schema::table('platforms', fn (Blueprint $table) => $table->timestamp('archived_at')->nullable());

        $configuredHosts = explode(',', (string) config('pwa.install_domains'));
        $hosts = array_unique(array_filter(
            array_map(fn (string $host): string => strtolower(trim($host)), $configuredHosts),
            fn (string $host): bool => filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                || (preg_match('/(?:^|\\.)[a-z][a-z0-9-]*$/D', $host)
                    && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false),
        ));
        foreach ($hosts ?: ['qvip.com'] as $host) {
            DB::table('site_domains')->insert(['host' => $host, 'purpose' => 'pwa', 'created_at' => now(), 'updated_at' => now()]);
        }

        $role = DB::table('admin_roles')->where('name', '会员运营')
            ->whereJsonContains('permissions', 'system.manage-roles')
            ->whereJsonContains('permissions', 'system.manage-admins')->first();
        if ($role !== null) {
            $permissions = json_decode($role->permissions, true, flags: JSON_THROW_ON_ERROR);
            DB::table('admin_roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values(array_unique([...$permissions, ...self::PERMISSIONS])), JSON_THROW_ON_ERROR),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('admin_roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode($role->permissions, true, flags: JSON_THROW_ON_ERROR);
            $remaining = array_values(array_diff($permissions, self::PERMISSIONS));
            if ($remaining !== $permissions) {
                DB::table('admin_roles')->where('id', $role->id)->update(['permissions' => json_encode($remaining, JSON_THROW_ON_ERROR)]);
            }
        }
        Schema::table('platforms', fn (Blueprint $table) => $table->dropColumn('archived_at'));
        Schema::table('promotion_channels', fn (Blueprint $table) => $table->dropColumn('promotion_host'));
        Schema::dropIfExists('site_domains');
    }
};
