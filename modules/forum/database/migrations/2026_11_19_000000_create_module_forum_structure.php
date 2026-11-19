<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_forum_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('visibility', 16)->default('all');
            $table->unsignedSmallInteger('links_after_days')->default(0);
            $table->timestamps();
        });

        Schema::create('module_forum_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ru', 120);
            $table->string('name_en', 120)->nullable();
            $table->string('description_ru', 500)->nullable();
            $table->string('description_en', 500)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['deleted_at', 'position', 'id'], 'forum_category_order_index');
        });

        Schema::create('module_forum_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('module_forum_categories')->restrictOnDelete();
            $table->string('name_ru', 120);
            $table->string('name_en', 120)->nullable();
            $table->string('description_ru', 500)->nullable();
            $table->string('description_en', 500)->nullable();
            $table->string('view_rule', 16)->default('all');
            $table->string('topic_rule', 16)->default('registered');
            $table->string('reply_rule', 16)->default('registered');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['category_id', 'deleted_at', 'position', 'id'], 'forum_section_order_index');
        });

        Schema::create('module_forum_moderators', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        DB::table('module_forum_settings')->insert([
            'id' => 1, 'visibility' => 'all', 'links_after_days' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('module_forum_moderators');
        Schema::dropIfExists('module_forum_sections');
        Schema::dropIfExists('module_forum_categories');
        Schema::dropIfExists('module_forum_settings');
    }
};
