<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_bindings', fn (Blueprint $table) => $table->string('account_name', 40)->nullable());

        DB::table('platform_bindings')->whereIn('id', DB::table('platform_binding_attempts')
            ->where('method', 'automatic')->where('status', 'succeeded')->whereNotNull('platform_binding_id')
            ->select('platform_binding_id'))
            ->update(['account_name' => DB::raw('query_phone')]);

        $this->updateAutomaticAttemptAccounts('account_name');
    }

    public function down(): void
    {
        $this->updateAutomaticAttemptAccounts('platform_account_id');

        Schema::table('platform_bindings', fn (Blueprint $table) => $table->dropColumn('account_name'));
    }

    private function updateAutomaticAttemptAccounts(string $column): void
    {
        DB::table('platform_binding_attempts')
            ->where('method', 'automatic')->where('status', 'succeeded')->whereNotNull('platform_binding_id')
            ->update(['platform_account_id' => DB::table('platform_bindings')->select($column)
                ->whereColumn('platform_bindings.id', 'platform_binding_attempts.platform_binding_id')]);
    }
};
