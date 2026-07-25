<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pages')) {
            Schema::create('pages', function (Blueprint $table): void {
                $table->id();
                $table->string('slug')->unique();
                $table->boolean('is_published')->default(false)->index();
                $table->boolean('show_in_header')->default(false)->index();
                $table->boolean('show_in_footer')->default(false)->index();
                $table->unsignedInteger('sort_order')->default(100)->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('page_translations')) {
            Schema::create('page_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
                $table->string('locale', 10);
                $table->string('title');
                $table->string('slug');
                $table->longText('body');
                $table->string('seo_title')->nullable();
                $table->text('seo_description')->nullable();
                $table->timestamps();

                $table->unique(['page_id', 'locale']);
                $table->unique(['locale', 'slug']);
                $table->index(['locale', 'page_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('page_translations');
        Schema::dropIfExists('pages');
    }
};
