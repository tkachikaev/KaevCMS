<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_donation_paypal_attempts', function (Blueprint $table): void {
            $table->string('expected_payee_id', 64)->nullable();
        });
        // A minimal immutable, uniquely keyed event inbox is required for durable acknowledgments,
        // including Sandbox events (which must never link to a player or Wallet).
        Schema::create('module_donation_paypal_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attempt_id')->constrained('module_donation_paypal_attempts')->restrictOnDelete();
            $table->string('environment', 8);
            $table->string('event_id', 190);
            $table->string('event_type', 90);
            $table->char('payload_sha256', 64);
            $table->longText('encrypted_raw_event'); // Laravel Crypt; bytes preserved and access-controlled
            $table->string('status', 16); // received | processed
            $table->timestamps();
            $table->unique(['environment', 'event_id'], 'paypal_webhook_event_unique');
            $table->index(['attempt_id', 'status']);
        });
    }

    public function down(): void
    {
        if (DB::table('module_donation_paypal_webhook_events')->exists()
            || DB::table('module_donation_paypal_attempts')->whereNotNull('expected_payee_id')->exists()) {
            throw new RuntimeException('Cannot discard PayPal evidence.');
        }
        Schema::dropIfExists('module_donation_paypal_webhook_events');
        Schema::table('module_donation_paypal_attempts', static function (Blueprint $table): void {
            $table->dropColumn('expected_payee_id');
        });
    }
};
