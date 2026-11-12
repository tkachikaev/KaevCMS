<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_news_comment_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->string('publication_mode', 24)->default('manual_review');
            $table->boolean('allow_user_editing')->default(true);
            $table->boolean('allow_editor_moderation')->default(false);
            $table->unsignedSmallInteger('max_comments_per_day')->default(20);
            $table->unsignedSmallInteger('max_comments_per_news_per_day')->default(5);
            $table->unsignedSmallInteger('min_submission_interval_seconds')->default(60);
            $table->unsignedSmallInteger('max_comment_length')->default(2000);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('module_news_comment_news_settings', function (Blueprint $table): void {
            $table->foreignId('news_id')->primary()->constrained('news')->cascadeOnDelete();
            $table->string('policy', 12)->default('inherit');
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('module_news_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('news_id')->constrained('news')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('module_news_comments')->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->text('pending_body')->nullable();
            $table->string('status', 24)->default('pending');
            $table->timestamp('pending_edited_at')->nullable();
            $table->timestamp('last_edit_submitted_at')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_by_author_at')->nullable();
            $table->foreignId('moderated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();

            $table->index(['news_id', 'parent_id', 'status', 'id'], 'news_comments_thread_index');
            $table->index(['user_id', 'created_at'], 'news_comments_author_created_index');
            $table->index(['status', 'created_at'], 'news_comments_queue_index');
            $table->index(['pending_edited_at', 'id'], 'news_comments_pending_edit_index');
        });

        Schema::create('module_news_comment_submission_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('news_id')->constrained('news')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at'], 'news_comment_events_user_created_index');
            $table->index(['user_id', 'news_id', 'created_at'], 'news_comment_events_news_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_news_comment_submission_events');
        Schema::dropIfExists('module_news_comments');
        Schema::dropIfExists('module_news_comment_news_settings');
        Schema::dropIfExists('module_news_comment_settings');
    }
};
