<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_DRIVER = 'l2j_mobius_ct0_interlude';

    private const NEW_DRIVER = 'l2j_mobius';

    public function up(): void
    {
        $this->rename(self::OLD_DRIVER, self::NEW_DRIVER);
    }

    public function down(): void
    {
        $this->rename(self::NEW_DRIVER, self::OLD_DRIVER);
    }

    private function rename(string $from, string $to): void
    {
        if (! Schema::hasTable('game_servers') || ! Schema::hasColumn('game_servers', 'driver')) {
            return;
        }

        DB::table('game_servers')
            ->where('driver', $from)
            ->update(['driver' => $to]);
    }
};
