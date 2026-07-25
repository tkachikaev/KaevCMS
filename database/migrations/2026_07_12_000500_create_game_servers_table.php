<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_servers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('rates', 100)->nullable();
            $table->string('chronicle', 100)->nullable();
            $table->string('mode', 100)->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();
        });

        $legacy = Schema::hasTable('cms_settings')
            ? DB::table('cms_settings')
                ->whereIn('key', [
                    'server.name',
                    'server.rates',
                    'server.chronicle',
                    'server.mode',
                ])
                ->pluck('value', 'key')
            : collect();

        $name = trim((string) ($legacy->get('server.name') ?? config('cms.server.name', 'L2Server x1')));
        if ($name === '') {
            $name = 'L2Server x1';
        }

        $rates = $this->legacyOrDefault($legacy, 'server.rates', 'cms.server.rates', 'x1');
        $chronicle = $this->legacyOrDefault($legacy, 'server.chronicle', 'cms.server.chronicle', 'High Five');
        $mode = $this->legacyOrDefault($legacy, 'server.mode', 'cms.server.mode', 'PvP');
        $now = now();

        DB::table('game_servers')->insert([
            'name' => $name,
            'rates' => $rates,
            'chronicle' => $chronicle,
            'mode' => $mode,
            'sort_order' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('game_servers');
    }

    private function legacyOrDefault($legacy, string $legacyKey, string $configKey, string $fallback): ?string
    {
        $value = $legacy->has($legacyKey)
            ? trim((string) $legacy->get($legacyKey))
            : trim((string) config($configKey, $fallback));

        return $value !== '' ? $value : null;
    }
};
