<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_donation_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('order_uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->unsignedInteger('coins');
            $table->char('currency_code', 3);
            $table->unsignedTinyInteger('exponent');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('total_minor');
            // A provider may be assigned in a later iteration; never invent an identifier or fee here.
            $table->string('provider_code', 64)->nullable();
            $table->string('provider_payment_id', 190)->nullable();
            $table->unsignedBigInteger('checkout_total_minor')->nullable();
            $table->string('status', 24)->default('draft');
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key'], 'donation_orders_user_request_unique');
            $table->index(['user_id', 'created_at'], 'donation_orders_user_created_index');
            $table->index(['status', 'expires_at'], 'donation_orders_status_expiry_index');
            $table->unique(['provider_code', 'provider_payment_id'], 'donation_orders_provider_payment_unique');
        });

        Schema::create('module_donation_order_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('module_donation_orders')->restrictOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('kind', 40);
            $table->timestamp('created_at');
            $table->index(['order_id', 'id'], 'donation_order_events_order_index');
        });
    }

    public function down(): void
    {
        // Never destroy order/payment provenance by rolling a module migration back.
        if ((Schema::hasTable('module_donation_orders') && DB::table('module_donation_orders')->exists())
            || (Schema::hasTable('module_donation_order_events') && DB::table('module_donation_order_events')->exists())) {
            throw new RuntimeException('Cannot roll back Donations orders with financial history.');
        }
        Schema::dropIfExists('module_donation_order_events');
        Schema::dropIfExists('module_donation_orders');
    }
};
