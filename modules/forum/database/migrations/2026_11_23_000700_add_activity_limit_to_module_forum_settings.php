<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_forum_settings', function (Blueprint $table): void {
            // Topics per tab in the "latest activity" table on the forum home; 0 hides it.
            $table->unsignedTinyInteger('activity_limit')->default(10)->after('links_after_days');
        });
    }

    public function down(): void
    {
        Schema::table('module_forum_settings', function (Blueprint $table): void {
            $table->dropColumn('activity_limit');
        });
    }
};
