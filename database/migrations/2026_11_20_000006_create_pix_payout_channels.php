<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSION = 'pix.manage-channels';

    public function up(): void
    {
        Schema::create('pix_payout_channels', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->unique();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::table('pix_withdrawals', function (Blueprint $table): void {
            $table->foreignId('payout_channel_id')->nullable()->constrained('pix_payout_channels')->restrictOnDelete();
            $table->string('payout_channel_name', 80)->nullable();
        });

        $this->updateRole(true);
    }

    public function down(): void
    {
        $this->updateRole(false);
        Schema::table('pix_withdrawals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payout_channel_id');
            $table->dropColumn('payout_channel_name');
        });
        Schema::dropIfExists('pix_payout_channels');
    }

    private function updateRole(bool $grant): void
    {
        $role = DB::table('admin_roles')->where('name', '会员运营');
        $stored = $role->value('permissions');
        if ($stored === null) {
            return;
        }

        $permissions = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        if ($grant && ! in_array('pix.review', $permissions, true)) {
            return;
        }

        $permissions = $grant ? array_unique([...$permissions, self::PERMISSION]) : array_diff($permissions, [self::PERMISSION]);
        $role->update(['permissions' => json_encode(array_values($permissions), JSON_THROW_ON_ERROR)]);
    }
};
