<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INTENT_BINDING_FOREIGN = 'registration_binding_attempt_fk';

    public function up(): void
    {
        $this->addMissingColumns('members', [
            'registration_ip' => fn (Blueprint $table) => $table->ipAddress('registration_ip')->nullable(),
            'registration_country' => fn (Blueprint $table) => $table->char('registration_country', 2)->nullable(),
            'registration_device_hash' => fn (Blueprint $table) => $table->char('registration_device_hash', 64)->nullable(),
        ]);
        foreach (['registration_ip', 'registration_device_hash'] as $column) {
            if (! Schema::hasIndex('members', [$column])) {
                Schema::table('members', fn (Blueprint $table) => $table->index($column));
            }
        }
        $this->addMissingColumns('member_verification_settings', [
            'mode' => fn (Blueprint $table) => $table->string('mode', 16)->nullable(),
            'ip_registration_limit' => fn (Blueprint $table) => $table->unsignedInteger('ip_registration_limit')->nullable(),
            'device_registration_limit' => fn (Blueprint $table) => $table->unsignedInteger('device_registration_limit')->nullable(),
        ]);
        if (! Schema::hasTable('member_cash_adjustments')) {
            Schema::create('member_cash_adjustments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('member_id')->constrained()->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->uuid('operation_key');
                $table->bigInteger('amount_cents');
                $table->unsignedBigInteger('balance_before_cents');
                $table->unsignedBigInteger('balance_after_cents');
                $table->string('reason', 300);
                $table->timestamp('created_at');
                $table->unique(['member_id', 'operation_key']);
            });
        }
        if (! Schema::hasTable('platform_binding_attempts')) {
            Schema::create('platform_binding_attempts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('member_id')->constrained()->cascadeOnDelete();
                $table->foreignId('platform_id')->constrained()->cascadeOnDelete();
                $table->foreignId('platform_binding_id')->nullable()->constrained()->nullOnDelete();
                $table->string('platform_account_id', 190)->nullable();
                $table->string('method', 12);
                $table->string('status', 12)->index();
                $table->string('failure_reason', 300)->nullable();
                $table->timestamp('callback_at')->nullable();
                $table->timestamps();
                $table->index(['member_id', 'platform_id', 'created_at']);
            });
        }
        $this->addMissingColumns('platform_registration_intents', [
            'platform_binding_attempt_id' => fn (Blueprint $table) => $table->foreignId('platform_binding_attempt_id')->nullable(),
        ]);
        foreach (Schema::getForeignKeys('platform_registration_intents') as $foreign) {
            if ($foreign['columns'] === ['platform_binding_attempt_id']) {
                return;
            }
        }
        Schema::table('platform_registration_intents', function (Blueprint $table): void {
            $table->foreign('platform_binding_attempt_id', self::INTENT_BINDING_FOREIGN)
                ->references('id')->on('platform_binding_attempts')->nullOnDelete();
        });
    }

    private function addMissingColumns(string $tableName, array $columns): void
    {
        $missing = array_filter(
            $columns,
            fn (string $column): bool => ! Schema::hasColumn($tableName, $column),
            ARRAY_FILTER_USE_KEY,
        );
        if ($missing !== []) {
            Schema::table($tableName, function (Blueprint $table) use ($missing): void {
                foreach ($missing as $define) {
                    $define($table);
                }
            });
        }
    }

    public function down(): void
    {
        $foreign = Schema::getConnection()->getDriverName() === 'sqlite'
            ? ['platform_binding_attempt_id'] : self::INTENT_BINDING_FOREIGN;
        Schema::table('platform_registration_intents', function (Blueprint $table) use ($foreign): void {
            $table->dropForeign($foreign);
            $table->dropColumn('platform_binding_attempt_id');
        });
        Schema::dropIfExists('platform_binding_attempts');
        Schema::dropIfExists('member_cash_adjustments');
        Schema::table('member_verification_settings', function (Blueprint $table): void {
            $table->dropColumn(['mode', 'ip_registration_limit', 'device_registration_limit']);
        });
        Schema::table('members', function (Blueprint $table): void {
            $table->dropIndex(['registration_ip']);
            $table->dropIndex(['registration_device_hash']);
            $table->dropColumn(['registration_ip', 'registration_country', 'registration_device_hash']);
        });
    }
};
