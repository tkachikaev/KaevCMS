<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_forum_topics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('section_id')->constrained('module_forum_sections')->restrictOnDelete();
            $table->string('title', 160);
            $table->string('status', 16)->default('open');
            $table->boolean('is_pinned')->default(false);
            $table->timestamp('hidden_at')->nullable();
            $table->string('author_type', 10);
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('author_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('author_name', 190);
            $table->string('last_author_name', 190);
            $table->unsignedInteger('reply_count')->default(0);
            $table->timestamp('last_post_at');
            $table->uuid('submission_key')->unique();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['section_id', 'deleted_at', 'hidden_at', 'is_pinned', 'last_post_at'], 'forum_topic_list_index');
        });

        Schema::create('module_forum_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('topic_id')->constrained('module_forum_topics')->restrictOnDelete();
            $table->string('author_type', 10);
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('author_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('author_name', 190);
            $table->string('author_role', 16)->nullable();
            $table->text('body_text');
            $table->uuid('submission_key')->nullable()->unique();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['topic_id', 'deleted_at', 'id'], 'forum_post_topic_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_forum_posts');
        Schema::dropIfExists('module_forum_topics');
    }
};
