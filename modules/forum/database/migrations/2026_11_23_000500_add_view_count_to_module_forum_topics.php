<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_forum_topics', function (Blueprint $table): void {
            // Existing topics start at zero: earlier views were never recorded.
            $table->unsignedInteger('view_count')->default(0)->after('reply_count');
        });
    }

    public function down(): void
    {
        Schema::table('module_forum_topics', function (Blueprint $table): void {
            $table->dropColumn('view_count');
        });
    }
};
