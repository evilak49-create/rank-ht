<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = ['red-packets.manage', 'red-packets.view', 'pix.view', 'pix.review', 'pix.resume'];

    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('invite_code', 32)->nullable()->unique();
            $table->text('pix_cpf')->nullable();
        });
        Schema::create('red_packet_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('threshold_cents');
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        DB::table('red_packet_rules')->insert(['threshold_cents' => 10000, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('red_packet_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('red_packet_rule_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('threshold_cents');
            $table->unsignedSmallInteger('initial_bps');
            $table->unsignedInteger('initial_cents');
            $table->unsignedInteger('balance_cents');
            $table->unsignedInteger('frozen_cents')->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['member_id', 'closed_at']);
        });
        Schema::create('red_packet_open_requests', function (Blueprint $table) {
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('red_packet_round_id')->constrained()->restrictOnDelete();
            $table->uuid('request_id');
            $table->primary(['member_id', 'request_id']);
        });
        Schema::create('red_packet_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inviter_id')->constrained('members')->restrictOnDelete();
            $table->foreignId('invitee_id')->unique()->constrained('members')->restrictOnDelete();
            $table->foreignId('red_packet_round_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('reward_cents')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['inviter_id', 'settled_at']);
        });
        Schema::create('pix_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->uuid('order_number')->unique();
            $table->uuid('request_id');
            $table->foreignId('member_id')->constrained()->restrictOnDelete();
            $table->foreignId('red_packet_round_id')->constrained()->restrictOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->text('cpf_snapshot');
            $table->string('status', 16)->index();
            $table->string('review_reason', 300)->nullable();
            $table->string('channel_reference', 190)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['member_id', 'request_id']);
        });
        Schema::create('red_packet_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('red_packet_round_id')->constrained()->restrictOnDelete();
            $table->foreignId('red_packet_invite_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('pix_withdrawal_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 16);
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('balance_after');
            $table->unsignedInteger('frozen_after');
            $table->json('calculation')->nullable();
            $table->timestamp('created_at');
            $table->unique(['pix_withdrawal_id', 'kind']);
        });
        Schema::create('pix_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pix_withdrawal_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('send_started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('needs_review_at')->nullable();
            $table->uuid('lease_key')->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamps();
        });
        Schema::create('pix_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pix_withdrawal_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event', 32);
            $table->timestamp('created_at');
        });
        $this->updateDefaultRole(add: true);
    }

    public function down(): void
    {
        $this->updateDefaultRole(add: false);
        foreach (['pix_events', 'pix_tasks', 'red_packet_entries', 'pix_withdrawals', 'red_packet_invites', 'red_packet_open_requests', 'red_packet_rounds', 'red_packet_rules'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('members', function (Blueprint $table) {
            $table->dropUnique(['invite_code']);
            $table->dropColumn(['invite_code', 'pix_cpf']);
        });
    }

    private function updateDefaultRole(bool $add): void
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
