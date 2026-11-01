<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_shop_products', function (Blueprint $table): void {
            $table->dropForeign(['category_id']);
        });
        Schema::table('module_shop_products', function (Blueprint $table): void {
            $table->unsignedBigInteger('category_id')->nullable()->change();
        });
        Schema::table('module_shop_products', function (Blueprint $table): void {
            $table->foreign('category_id')->references('id')->on('module_shop_categories')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Rollback cannot silently discard newly created direct-section products.
        if (\Illuminate\Support\Facades\DB::table('module_shop_products')->whereNull('category_id')->exists()) {
            throw new RuntimeException('Move direct-section Shop products before rolling back this migration.');
        }
        Schema::table('module_shop_products', function (Blueprint $table): void {
            $table->dropForeign(['category_id']);
        });
        Schema::table('module_shop_products', function (Blueprint $table): void {
            $table->unsignedBigInteger('category_id')->nullable(false)->change();
        });
        Schema::table('module_shop_products', function (Blueprint $table): void {
            $table->foreign('category_id')->references('id')->on('module_shop_categories')->restrictOnDelete();
        });
    }
};
