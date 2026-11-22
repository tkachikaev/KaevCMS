<?php

use Illuminate\Database\Migrations\Migration;
use KaevCMS\Modules\Forum\Services\ForumStructure;

return new class extends Migration
{
    public function up(): void
    {
        // First enable (or upgrade from F3): initialise only an untouched forum.
        // ForumStructure also checks soft-deleted categories to protect prior choices.
        app(ForumStructure::class)->initialStructure();
    }

    public function down(): void
    {
        // Never remove categories and sections the administrator may have edited.
    }
};
