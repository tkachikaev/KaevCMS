<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_donation_payment_preparations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('module_donation_orders')->restrictOnDelete();
            $table->string('provider_code', 64);
            $table->string('mode', 8); // test/local or live; neither starts a transfer in C2.
            $table->string('checkout_method', 8); // POST or GET, not an executable checkout URL.
            $table->string('provider_label', 64)->unique();
            $table->unsignedBigInteger('payer_amount_minor');
            $table->char('currency_code', 3);
            $table->timestamps();
            $table->index(['provider_code', 'created_at'], 'donation_preparations_provider_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('module_donation_payment_preparations')
            && DB::table('module_donation_payment_preparations')->exists()) {
            throw new RuntimeException('Cannot roll back payment preparation records.');
        }
        Schema::dropIfExists('module_donation_payment_preparations');
    }
};
