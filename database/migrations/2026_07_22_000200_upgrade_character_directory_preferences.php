<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_character_preferences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('schema_version')->default(1)->after('view_mode');
        });

        DB::table('user_character_preferences')->update([
            'view_mode' => 'all',
            'schema_version' => 2,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('user_character_preferences', function (Blueprint $table): void {
            $table->dropColumn('schema_version');
        });
    }
};
