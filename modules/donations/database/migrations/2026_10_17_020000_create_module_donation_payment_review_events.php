<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_donation_payment_review_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('module_donation_orders')->restrictOnDelete();
            $table->foreignId('receipt_id')->constrained('module_donation_payment_receipts')->restrictOnDelete();
            $table->foreignId('admin_id')->constrained('admins')->restrictOnDelete();
            $table->uuid('request_uuid')->unique();
            $table->char('fingerprint', 64);
            $table->string('kind', 32); // Reported by administrator, NOT automatically verified by provider.
            $table->string('source', 16); // manual only in this version
            $table->text('reason'); // Human-readable evidence note; never a secret or card data.
            $table->timestamps();
            $table->index(['order_id', 'id'], 'donation_review_order_history_index');
            $table->index(['kind', 'id'], 'donation_review_kind_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('module_donation_payment_review_events')
            && DB::table('module_donation_payment_review_events')->exists()) {
            throw new RuntimeException('Cannot discard Donations dispute and refund review evidence.');
        }
        Schema::dropIfExists('module_donation_payment_review_events');
    }
};
