<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('balance')->default(0);
            $table->boolean('is_blocked')->default(false);
            $table->timestamps();
        });

        Schema::create('wallet_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->uuid('operation_uuid')->unique();
            $table->string('source', 64);
            $table->string('source_key', 120);
            $table->char('request_fingerprint', 64);
            $table->string('direction', 16);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('balance_before');
            $table->unsignedBigInteger('balance_after');
            $table->string('reference', 190)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('actor_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamp('occurred_at')->useCurrent();

            $table->unique(['source', 'source_key'], 'wallet_entries_source_key_unique');
            $table->index(['wallet_id', 'id'], 'wallet_entries_wallet_history_index');
            $table->index(['source', 'occurred_at'], 'wallet_entries_source_date_index');
        });

        // Existing site users start with zero coins; no changes to users or game databases.
        // New users' wallets will be created idempotently by the Wallet service in A2.
        $now = now();
        DB::table('wallets')->insertUsing(
            ['user_id', 'balance', 'is_blocked', 'created_at', 'updated_at'],
            DB::table('users')->select('id')->selectRaw(
                '0, 0, ? AS created_at, ? AS updated_at',
                [$now, $now],
            ),
        );
    }

    public function down(): void
    {
        // Never silently erase funded wallets or their financial history on rollback.
        if (Schema::hasTable('wallet_entries') && DB::table('wallet_entries')->exists()) {
            throw new RuntimeException('Wallet rollback is blocked: transaction history exists.');
        }
        if (Schema::hasTable('wallets') && DB::table('wallets')->where('balance', '>', 0)->exists()) {
            throw new RuntimeException('Wallet rollback is blocked: nonzero balances exist.');
        }

        Schema::dropIfExists('wallet_entries');
        Schema::dropIfExists('wallets');
    }
};
