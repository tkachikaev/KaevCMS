<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigation_links', function (Blueprint $table): void {
            $table->id();
            $table->string('url', 2048);
            $table->boolean('is_active')->default(true);
            $table->boolean('show_in_header')->default(true);
            $table->boolean('show_in_footer')->default(false);
            $table->boolean('open_new_tab')->default(false);
            $table->unsignedInteger('sort_order')->default(100);
            $table->timestamps();
            $table->index(['is_active', 'show_in_header', 'sort_order']);
            $table->index(['is_active', 'show_in_footer', 'sort_order']);
        });

        Schema::create('navigation_link_translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('navigation_link_id')->constrained('navigation_links')->cascadeOnDelete();
            $table->string('locale', 12);
            $table->string('title', 160);
            $table->unique(['navigation_link_id', 'locale'], 'navigation_link_locale_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_link_translations');
        Schema::dropIfExists('navigation_links');
    }
};
