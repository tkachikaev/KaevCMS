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
            // JSON list of image host names; an empty list turns forum images off.
            $table->text('image_hosts')->nullable()->after('links_after_days');
        });

        DB::table('module_forum_settings')->where('id', 1)->update([
            'image_hosts' => json_encode(['iili.io', 'i.imgur.com', 'i.postimg.cc', 'i.ibb.co']),
        ]);
    }

    public function down(): void
    {
        Schema::table('module_forum_settings', function (Blueprint $table): void {
            $table->dropColumn('image_hosts');
        });
    }
};
