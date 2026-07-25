<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reward_inventory_items')) {
            if (Schema::hasColumn('reward_inventory_items', 'delivered_at')
                && ! Schema::hasColumn('reward_inventory_items', 'transferred_at')) {
                Schema::table('reward_inventory_items', function (Blueprint $table): void {
                    $table->renameColumn('delivered_at', 'transferred_at');
                });
            }

            DB::table('reward_inventory_items')
                ->where('status', 'delivered')
                ->update(['status' => 'transferred']);
        }

        if (Schema::hasTable('reward_deliveries')) {
            if (Schema::hasColumn('reward_deliveries', 'completed_at')
                && ! Schema::hasColumn('reward_deliveries', 'queued_at')) {
                Schema::table('reward_deliveries', function (Blueprint $table): void {
                    $table->renameColumn('completed_at', 'queued_at');
                });
            }

            if (Schema::hasColumn('reward_deliveries', 'started_at')) {
                Schema::table('reward_deliveries', function (Blueprint $table): void {
                    $table->dropColumn('started_at');
                });
            }

            DB::table('reward_deliveries')
                ->where('status', 'delivered')
                ->update(['status' => 'queued']);

            DB::table('reward_deliveries')
                ->whereIn('status', ['pending', 'processing'])
                ->update([
                    'status' => 'review',
                    'failure_code' => 'legacy_bridge_operation_requires_review',
                ]);
        }

        if (Schema::hasTable('jobs')) {
            DB::table('jobs')->where('queue', 'rewards')->delete();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('reward_inventory_items')) {
            DB::table('reward_inventory_items')
                ->where('status', 'transferred')
                ->update(['status' => 'delivered']);

            if (Schema::hasColumn('reward_inventory_items', 'transferred_at')
                && ! Schema::hasColumn('reward_inventory_items', 'delivered_at')) {
                Schema::table('reward_inventory_items', function (Blueprint $table): void {
                    $table->renameColumn('transferred_at', 'delivered_at');
                });
            }
        }

        if (Schema::hasTable('reward_deliveries')) {
            DB::table('reward_deliveries')
                ->where('status', 'queued')
                ->update(['status' => 'delivered']);

            if (Schema::hasColumn('reward_deliveries', 'queued_at')
                && ! Schema::hasColumn('reward_deliveries', 'completed_at')) {
                Schema::table('reward_deliveries', function (Blueprint $table): void {
                    $table->renameColumn('queued_at', 'completed_at');
                });
            }

            if (! Schema::hasColumn('reward_deliveries', 'started_at')) {
                Schema::table('reward_deliveries', function (Blueprint $table): void {
                    $table->timestamp('started_at')->nullable();
                });
            }
        }
    }
};
