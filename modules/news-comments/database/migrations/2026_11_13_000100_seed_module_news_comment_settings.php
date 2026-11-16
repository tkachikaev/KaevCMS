<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('module_news_comment_settings')
            || DB::table('module_news_comment_settings')->where('id', 1)->exists()) {
            return;
        }

        DB::table('module_news_comment_settings')->insert([
            'id' => 1,
            'enabled' => false,
            'publication_mode' => 'manual_review',
            'allow_user_editing' => true,
            'allow_editor_moderation' => false,
            'max_comments_per_day' => 20,
            'max_comments_per_news_per_day' => 5,
            'min_submission_interval_seconds' => 60,
            'max_comment_length' => 2000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Keep administrator settings on a rollback.
    }
};
