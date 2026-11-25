<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_bindings')->whereNull('account_name')
            ->update(['account_name' => DB::raw('query_phone')]);

        DB::table('platform_binding_attempts')->where('status', 'succeeded')->whereNotNull('platform_binding_id')
            ->update(['platform_account_id' => DB::table('platform_bindings')->select('account_name')
                ->whereColumn('platform_bindings.id', 'platform_binding_attempts.platform_binding_id')]);
    }

    public function down(): void
    {
        // Preserve recovered account names on rollback.
    }
};
