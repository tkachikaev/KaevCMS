<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_donation_paypal_attempts', function (Blueprint $table): void {
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('module_donation_paypal_attempts')->where('purpose', 'admin_test')->exists()) {
            throw new RuntimeException('Cannot discard PayPal administrator test actor evidence.');
        }
        Schema::table('module_donation_paypal_attempts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('admin_id');
        });
    }
};
