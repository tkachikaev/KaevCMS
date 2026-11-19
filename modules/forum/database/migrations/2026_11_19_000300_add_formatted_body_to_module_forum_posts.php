<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_forum_posts', function (Blueprint $table): void {
            // NULL means a legacy F4 plain-text post. Never reinterpret it as HTML.
            $table->text('body_html')->nullable()->after('body_text');
        });
    }

    public function down(): void
    {
        Schema::table('module_forum_posts', function (Blueprint $table): void {
            $table->dropColumn('body_html');
        });
    }
};
