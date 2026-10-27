<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Administrative probes are intentionally NOT donation orders or wallet transactions.
        Schema::create('module_donation_freekassa_admin_tests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('reference')->unique();
            $table->foreignId('admin_id')->constrained('admins')->restrictOnDelete();
            $table->string('merchant_id', 32);
            $table->char('currency_code', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 16)->default('pending');
            $table->string('provider_payment_id', 64)->nullable()->unique();
            $table->char('notification_sha256', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['admin_id', 'created_at'], 'donation_fk_admin_tests_created_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('module_donation_freekassa_admin_tests')
            && DB::table('module_donation_freekassa_admin_tests')->exists()) {
            throw new RuntimeException('Cannot delete FreeKassa administrative payment evidence.');
        }
        Schema::dropIfExists('module_donation_freekassa_admin_tests');
    }
};
