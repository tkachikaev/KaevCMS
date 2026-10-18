<?php

use App\Services\Pages\DownloadPages;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(DownloadPages::class)->migrateEditableContent();
    }

    public function down(): void
    {
        // Editable content belongs to the owner; never remove it on rollback.
    }
};
