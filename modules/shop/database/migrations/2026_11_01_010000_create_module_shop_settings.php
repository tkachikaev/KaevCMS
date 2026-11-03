<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_shop_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('setting_key', 16)->unique();
            $table->boolean('sales_enabled')->default(false);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('module_shop_settings')->insert([
            'setting_key' => 'global',
            'sales_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Server settings contain no items yet. Catalog tables will be introduced with
        // their own additive migration when categories and products are implemented.
        Schema::create('module_shop_server_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_server_id')->unique()->constrained('game_servers')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_shop_server_settings');
        Schema::dropIfExists('module_shop_settings');
    }
};
