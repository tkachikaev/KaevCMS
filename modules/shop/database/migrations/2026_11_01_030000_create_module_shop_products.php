<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_shop_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('module_shop_categories')->restrictOnDelete();
            $table->string('type', 12); // item | bundle; type cannot be changed after creation.
            $table->string('name_ru', 120);
            $table->string('name_en', 120);
            $table->unsignedInteger('price_coins');
            $table->unsignedBigInteger('item_id')->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(false);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->index(['category_id', 'sort_order', 'id'], 'shop_products_category_order_idx');
        });

        // Bundle components are configuration, not fulfillment history.
        Schema::create('module_shop_bundle_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('module_shop_products')->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->unsignedInteger('quantity');
            $table->timestamps();
            $table->unique(['product_id', 'item_id'], 'shop_bundle_product_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_shop_bundle_items');
        Schema::dropIfExists('module_shop_products');
    }
};
