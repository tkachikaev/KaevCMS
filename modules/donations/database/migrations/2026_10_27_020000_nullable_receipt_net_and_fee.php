<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_donation_payment_receipts', function (Blueprint $table): void {
            $table->unsignedBigInteger('received_amount_minor')->nullable()->change();
            // Encrypted 64 KiB PayPal raw JSON exceeds MySQL TEXT after base64 encryption.
            $table->longText('encrypted_notification')->change();
            $table->char('received_currency_code', 3)->nullable();
            $table->unsignedBigInteger('fee_amount_minor')->nullable();
            $table->char('fee_currency_code', 3)->nullable();
        });
        // Existing receipts keep their exact amount and are interpreted in gross currency.
        // No destructive rewrite or backfill of historic payment evidence.
    }

    public function down(): void
    {
        if (DB::table('module_donation_payment_receipts')->exists()) {
            throw new RuntimeException('Cannot roll back Donations receipt metadata with financial evidence.');
        }
        Schema::table('module_donation_payment_receipts', function (Blueprint $table): void {
            $table->dropColumn(['received_currency_code', 'fee_amount_minor', 'fee_currency_code']);
            $table->unsignedBigInteger('received_amount_minor')->nullable(false)->change();
            $table->text('encrypted_notification')->change();
        });
    }
};
