<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only fully settled purchases are recorded. The wallet debit, inventory grant,
        // snapshot and audit all participate in the same database transaction.
        Schema::create('module_shop_purchases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('game_server_id')->constrained('game_servers')->restrictOnDelete();
            // Historical product ID survives removal of the editable catalog row.
            $table->unsignedBigInteger('product_id');
            $table->string('product_type', 12);
            $table->string('product_name_ru', 120);
            $table->string('product_name_en', 120);
            $table->unsignedInteger('price_coins');
            $table->json('items_snapshot');
            $table->foreignId('wallet_entry_id')->unique()->constrained('wallet_entries')->restrictOnDelete();
            $table->foreignId('reward_inventory_grant_id')->unique()->constrained('reward_inventory_grants')->restrictOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'created_at'], 'shop_purchases_user_date_idx');
            $table->index(['game_server_id', 'created_at'], 'shop_purchases_server_date_idx');
            $table->index('product_id', 'shop_purchases_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_shop_purchases');
    }
};
