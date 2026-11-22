<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_forum_settings', function (Blueprint $table): void {
            // An explicit on/off switch for the activity table; the count stays separate.
            $table->boolean('activity_enabled')->default(true)->after('activity_limit');
        });

        // A count of 0 used to mean "hidden": keep it hidden and restore a usable count.
        DB::table('module_forum_settings')->where('activity_limit', 0)->update([
            'activity_enabled' => false,
            'activity_limit' => 10,
        ]);
    }

    public function down(): void
    {
        Schema::table('module_forum_settings', function (Blueprint $table): void {
            $table->dropColumn('activity_enabled');
        });
    }
};
