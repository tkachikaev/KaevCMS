<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catalog metadata is retained: deletion of a configured GameServer is blocked.
        Schema::create('module_shop_tabs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_server_id')->constrained('game_servers')->restrictOnDelete();
            $table->string('name_ru', 120);
            $table->string('name_en', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->index(['game_server_id', 'sort_order', 'id'], 'shop_tabs_server_order_idx');
        });

        Schema::create('module_shop_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tab_id')->constrained('module_shop_tabs')->restrictOnDelete();
            $table->string('name_ru', 120);
            $table->string('name_en', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->index(['tab_id', 'sort_order', 'id'], 'shop_categories_tab_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_shop_categories');
        Schema::dropIfExists('module_shop_tabs');
    }
};
