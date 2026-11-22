<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_forum_posts', function (Blueprint $table): void {
            $table->unsignedInteger('edit_version')->default(0);
            $table->timestamp('removed_at')->nullable();
            $table->string('removed_by', 16)->nullable();
        });

        Schema::create('module_forum_post_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained('module_forum_posts')->cascadeOnDelete();
            $table->string('action', 16);
            $table->text('body_text');
            $table->text('body_html')->nullable();
            $table->string('changed_by_type', 10);
            $table->unsignedBigInteger('changed_by_id')->nullable();
            $table->string('changed_by_name', 190);
            $table->timestamp('created_at');
            $table->timestamp('expires_at');
            $table->index(['expires_at', 'id'], 'forum_revision_expiry_index');
            $table->index(['post_id', 'expires_at', 'id'], 'forum_revision_post_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_forum_post_revisions');

        Schema::table('module_forum_posts', function (Blueprint $table): void {
            $table->dropColumn(['edit_version', 'removed_at', 'removed_by']);
        });
    }
};
