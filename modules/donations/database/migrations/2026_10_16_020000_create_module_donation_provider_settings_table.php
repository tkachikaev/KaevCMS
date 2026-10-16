<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_donation_provider_settings', function (Blueprint $table): void {
            $table->string('provider_code', 40)->primary();
            $table->boolean('enabled')->default(false);
            $table->string('mode', 8)->default('test');
            $table->json('currency_codes');
            $table->text('credentials_ciphertext')->nullable();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Real payment orders must remain recoverable after a module update failure.
        if (Schema::hasTable('module_donation_orders')
            && DB::table('module_donation_orders')->whereNotNull('provider_code')->exists()) {
            throw new RuntimeException('Cannot remove payment-provider settings while provider orders exist.');
        }
        Schema::dropIfExists('module_donation_provider_settings');
    }
};
