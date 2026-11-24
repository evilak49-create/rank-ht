<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CHANNELS = ['u2cpay' => 'U2Cpay', '4zpay' => '4zpay'];

    public function up(): void
    {
        $now = now();
        foreach (self::CHANNELS as $driver => $label) {
            if (DB::table('pix_payout_channels')->where('driver', $driver)->exists()) {
                continue;
            }

            $name = $label;
            for ($suffix = 2; DB::table('pix_payout_channels')->where('name', $name)->exists(); $suffix++) {
                $name = $label.' ('.$suffix.')';
            }

            DB::table('pix_payout_channels')->insert([
                'name' => $name, 'driver' => $driver, 'enabled' => false,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Retain channel IDs and any settings saved after deployment.
    }
};
