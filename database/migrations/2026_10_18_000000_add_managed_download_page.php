<?php

use App\Services\Pages\DownloadPages;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['download_client_url', 'download_patch_url'] as $column) {
            if (! Schema::hasColumn('pages', $column)) {
                Schema::table('pages', fn (Blueprint $table) => $table->string($column, 2048)->nullable());
            }
        }

        app(DownloadPages::class)->initialize();
    }

    public function down(): void
    {
        foreach (['download_client_url', 'download_patch_url'] as $column) {
            if (Schema::hasColumn('pages', $column)) {
                Schema::table('pages', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
        // Keep editable content: rolling back must not delete an owner's page.
    }
};
