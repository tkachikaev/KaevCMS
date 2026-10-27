<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_donation_paypal_attempts', function (Blueprint $table): void {
            $table->string('approval_url', 2048)->nullable();
        });
    }

    public function down(): void
    {
        // Do not silently discard evidence for outstanding purchases.
        if (\Illuminate\Support\Facades\DB::table('module_donation_paypal_attempts')->whereNotNull('approval_url')->exists()) {
            throw new \RuntimeException('Cannot remove saved PayPal approval links.');
        }
        Schema::table('module_donation_paypal_attempts', function (Blueprint $table): void {
            $table->dropColumn('approval_url');
        });
    }
};
