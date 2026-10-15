<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_status_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignId('actor_admin_id')->constrained('admins')->restrictOnDelete();
            $table->string('operation_key', 120)->unique();
            $table->char('request_fingerprint', 64);
            $table->boolean('was_blocked');
            $table->boolean('is_blocked');
            $table->string('reason', 190);
            $table->timestamp('occurred_at')->useCurrent();
            $table->index(['wallet_id', 'id'], 'wallet_status_events_history_index');
        });
    }

    public function down(): void
    {
        // These records are permanent security/financial history, not normal audit-log retention.
        if (Schema::hasTable('wallet_status_events') && DB::table('wallet_status_events')->exists()) {
            throw new RuntimeException('Wallet status rollback is blocked: status history exists.');
        }
        Schema::dropIfExists('wallet_status_events');
    }
};
