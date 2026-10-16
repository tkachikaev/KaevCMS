<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_donation_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('show_in_account')->default(false);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('module_donation_settings')->insert([
            'id' => 1,
            'show_in_account' => false,
            'updated_by_admin_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Module rollback/recovery may safely discard B1 preferences only.
        // Never drop core wallets, entries, or any future donation payment records.
        Schema::dropIfExists('module_donation_settings');
    }
};
