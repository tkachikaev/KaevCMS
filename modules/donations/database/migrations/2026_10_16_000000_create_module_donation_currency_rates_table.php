<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_donation_settings', function (Blueprint $table): void {
            $table->unsignedInteger('min_coins')->default(1);
            $table->unsignedInteger('max_coins')->default(1000000);
        });

        Schema::create('module_donation_currency_rates', function (Blueprint $table): void {
            $table->char('currency_code', 3)->primary();
            $table->unsignedTinyInteger('exponent');
            $table->unsignedBigInteger('unit_price_minor');
            $table->boolean('enabled')->default(false);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Preserve configured tariffs on accidental rollback; future orders must never lose their price provenance.
        if (Schema::hasTable('module_donation_currency_rates') && DB::table('module_donation_currency_rates')->exists()) {
            throw new RuntimeException('Cannot roll back configured Donations currency rates.');
        }
        Schema::dropIfExists('module_donation_currency_rates');
        Schema::table('module_donation_settings', function (Blueprint $table): void {
            $table->dropColumn(['min_coins', 'max_coins']);
        });
    }
};
