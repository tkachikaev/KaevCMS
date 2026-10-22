<?php

use App\Services\Pages\AboutPages;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(AboutPages::class)->initialize();
    }

    public function down(): void
    {
        // Keep editable content: rolling back must not delete an owner's page.
    }
};
