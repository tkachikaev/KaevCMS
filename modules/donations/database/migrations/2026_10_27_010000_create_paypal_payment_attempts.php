<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No payment provider is activated by this schema change.
        Schema::create('module_donation_paypal_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained('module_donation_orders')->restrictOnDelete();
            $table->foreignId('preparation_id')->nullable()->constrained('module_donation_payment_preparations')->restrictOnDelete();
            $table->string('purpose', 16); // player | admin_test; admin_test has no order or Wallet link.
            $table->string('environment', 8); // live | sandbox
            $table->string('merchant_identity', 190); // fixed merchant/config identity at creation
            $table->string('status', 32); // reserved, creating, created, ... requires_review
            $table->uuid('create_request_id')->unique();
            $table->uuid('capture_request_id')->unique();
            $table->string('invoice_id', 64)->unique(); // unique across attempts, not per local order
            $table->string('paypal_order_id', 190)->nullable();
            $table->string('paypal_capture_id', 190)->nullable();
            $table->unsignedBigInteger('gross_amount_minor');
            $table->char('currency_code', 3);
            $table->unsignedTinyInteger('exponent');
            $table->string('diagnostic_code', 80)->nullable(); // sanitized; never store credentials
            $table->timestamp('create_started_at')->nullable();
            $table->timestamp('capture_started_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
            $table->unique(['environment', 'merchant_identity', 'paypal_order_id'], 'paypal_attempts_remote_order_unique');
            $table->unique(['environment', 'merchant_identity', 'paypal_capture_id'], 'paypal_attempts_remote_capture_unique');
            $table->index(['order_id', 'status'], 'paypal_attempts_order_status');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('module_donation_paypal_attempts')
            && DB::table('module_donation_paypal_attempts')->exists()) {
            throw new RuntimeException('Cannot roll back PayPal attempt evidence.');
        }
        Schema::dropIfExists('module_donation_paypal_attempts');
    }
};
