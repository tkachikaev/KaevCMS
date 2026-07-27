<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('reward_inventory_grants', 'operation_uuid')) {
            Schema::table('reward_inventory_grants', function (Blueprint $table): void {
                $table->uuid('operation_uuid')->nullable()->after('id');
            });
        }

        DB::table('reward_inventory_grants')
            ->whereNull('operation_uuid')
            ->orderBy('id')
            ->chunkById(100, static function (iterable $grants): void {
                foreach ($grants as $grant) {
                    $grantId = (int) data_get($grant, 'id');
                    DB::table('reward_inventory_grants')
                        ->where('id', $grantId)
                        ->update(['operation_uuid' => (string) Str::uuid()]);
                }
            });

        Schema::table('reward_inventory_grants', function (Blueprint $table): void {
            $table->uuid('operation_uuid')->nullable(false)->change();
            $table->unique('operation_uuid', 'reward_inventory_grants_operation_uuid_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('reward_inventory_grants', 'operation_uuid')) {
            return;
        }

        Schema::table('reward_inventory_grants', function (Blueprint $table): void {
            $table->dropUnique('reward_inventory_grants_operation_uuid_unique');
            $table->dropColumn('operation_uuid');
        });
    }
};
