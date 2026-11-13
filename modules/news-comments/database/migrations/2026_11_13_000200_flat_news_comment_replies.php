<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_news_comments', function (Blueprint $table): void {
            $table->dropForeign(['parent_id']);
            $table->foreign('parent_id')
                ->references('id')
                ->on('module_news_comments')
                ->nullOnDelete();
            $table->timestamp('deleted_by_admin_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('module_news_comments', function (Blueprint $table): void {
            $table->dropForeign(['parent_id']);
            $table->foreign('parent_id')
                ->references('id')
                ->on('module_news_comments')
                ->cascadeOnDelete();
            $table->dropColumn('deleted_by_admin_at');
        });
    }
};
