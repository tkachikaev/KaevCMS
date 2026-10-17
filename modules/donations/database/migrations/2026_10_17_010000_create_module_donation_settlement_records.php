<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_donation_payment_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('module_donation_orders')->restrictOnDelete();
            $table->foreignId('preparation_id')->constrained('module_donation_payment_preparations')->restrictOnDelete();
            $table->string('provider_code', 64);
            $table->string('provider_payment_id', 190);
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('payer_amount_minor');
            $table->unsignedBigInteger('received_amount_minor');
            $table->string('notification_sha256', 64);
            $table->text('encrypted_notification'); // Laravel Crypt, verified again before retries
            $table->string('status', 24); // pending | test | review | credited
            $table->string('review_reason', 64)->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();
            $table->unique(['provider_code', 'provider_payment_id'], 'donation_receipts_provider_operation_unique');
            $table->index(['order_id', 'status'], 'donation_receipts_order_status_index');
            $table->index(['status', 'id'], 'donation_receipts_reconcile_index');
        });

        Schema::create('module_donation_payment_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('module_donation_orders')->restrictOnDelete();
            $table->foreignId('receipt_id')->unique()->constrained('module_donation_payment_receipts')->restrictOnDelete();
            $table->foreignId('wallet_entry_id')->unique()->constrained('wallet_entries')->restrictOnDelete();
            $table->timestamp('created_at');
        });

        Schema::create('module_donation_recovery_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('preparation_id')->constrained('module_donation_payment_preparations')->restrictOnDelete();
            $table->string('provider_operation_id', 190);
            $table->string('provider_status', 24);
            $table->timestamp('created_at');
            $table->timestamp('updated_at');
            $table->unique(['preparation_id', 'provider_operation_id'], 'donation_recovery_operation_unique');
        });
    }

    public function down(): void
    {
        foreach (['module_donation_payment_settlements', 'module_donation_payment_receipts', 'module_donation_recovery_candidates'] as $name) {
            if (Schema::hasTable($name) && DB::table($name)->exists()) {
                throw new RuntimeException('Cannot roll back Donations financial evidence.');
            }
        }
        Schema::dropIfExists('module_donation_recovery_candidates');
        Schema::dropIfExists('module_donation_payment_settlements');
        Schema::dropIfExists('module_donation_payment_receipts');
    }
};
