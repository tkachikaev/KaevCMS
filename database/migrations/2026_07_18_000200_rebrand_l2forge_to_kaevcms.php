<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cms_settings')) {
            return;
        }

        DB::table('cms_settings')
            ->where(function (Builder $query): void {
                $query
                    ->where('key', 'site.name')
                    ->orWhere('key', 'like', 'site.name.%');
            })
            ->where('value', 'L2Forge CMS')
            ->update([
                'value' => 'KaevCMS',
                'updated_at' => now(),
            ]);

        DB::table('cms_settings')
            ->where(function (Builder $query): void {
                $query
                    ->where('key', 'site.footer_text')
                    ->orWhere('key', 'like', 'site.footer_text.%');
            })
            ->whereIn('value', [
                '© 2026 L2Forge-CMS',
                '© 2026 L2Forge CMS',
            ])
            ->update([
                'value' => '© 2026 KaevCMS',
                'updated_at' => now(),
            ]);

        DB::table('cms_settings')
            ->where('key', 'mail.from_name')
            ->where('value', 'L2Forge CMS')
            ->update([
                'value' => 'KaevCMS',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Branding upgrades intentionally preserve the current administrator-selected values.
    }
};
