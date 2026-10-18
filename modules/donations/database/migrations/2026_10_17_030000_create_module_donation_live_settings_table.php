<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_donation_live_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('mode', 8)->default('off');
            $table->foreignId('pilot_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
        DB::table('module_donation_live_settings')->insert([
            'id' => 1, 'mode' => 'off', 'pilot_user_id' => null,
            'updated_by_admin_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Never roll back a configuration that could leave paid orders in flight.
        if (Schema::hasTable('module_donation_live_settings')) {
            $mode = DB::table('module_donation_live_settings')->where('id', 1)->value('mode');
            if ($mode !== 'off' || (Schema::hasTable('module_donation_payment_preparations')
                && DB::table('module_donation_payment_preparations')->where('mode', 'live')->exists())) {
                throw new RuntimeException('Cannot remove live payment controls while payments may exist.');
            }
        }
        Schema::dropIfExists('module_donation_live_settings');
    }
};
