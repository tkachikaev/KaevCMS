<?php

namespace Tests\Unit;

use App\Models\GameServer;
use App\Services\GameWorld\MobiusGameSchemaInspector;
use App\Services\GameWorld\MobiusGameSchemaProfile;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class MobiusGameSchemaInspectorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        foreach (['castle', 'heroes', 'clan_data', 'characters'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_inspector_detects_legacy_schema_and_optional_capabilities(): void
    {
        $this->createCharactersTable('karma');
        $this->createClanTable();
        $this->createHeroesTable();
        $this->createCastleTable();

        $profile = app(MobiusGameSchemaInspector::class)->inspect(DB::connection(), 'Interlude');

        $this->assertSame(MobiusGameSchemaProfile::LEGACY, $profile->name);
        $this->assertSame('karma', $profile->reputationColumn);
        $this->assertTrue($profile->heroesAvailable);
        $this->assertTrue($profile->castlesAvailable);
    }

    public function test_inspection_reads_one_column_listing_per_table_and_is_remembered_per_server(): void
    {
        $this->createCharactersTable('karma');
        $this->createClanTable();
        $this->createHeroesTable();
        $this->createCastleTable();
        $inspector = app(MobiusGameSchemaInspector::class);

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $inspector->inspect(DB::connection(), 'Interlude');
        // Asking column by column cost 55 information_schema queries on a real game database. One
        // listing per table is 4 queries on MySQL; SQLite needs two statements per listing.
        $this->assertLessThanOrEqual(8, $queries, 'Inspecting must read each table\'s columns once.');

        $server = GameServer::factory()->create(['chronicle' => 'Interlude']);
        $inspections = 0;
        $inspect = static function () use (&$inspections, $inspector): MobiusGameSchemaProfile {
            $inspections++;

            return $inspector->inspect(DB::connection(), 'Interlude');
        };
        $inspector->remembered($server, $inspect);
        $profile = $inspector->remembered($server, $inspect);
        $this->assertSame(1, $inspections, 'The character list and statistics must share one remembered profile.');
        $this->assertSame('karma', $profile->reputationColumn);

        $this->travel(5)->seconds(); // The key holds updated_at to the second.
        $server->forceFill(['name' => 'Renamed'])->save();
        $inspector->remembered($server->fresh(), $inspect);
        $this->assertSame(2, $inspections, 'Editing the server must inspect its database again.');
    }

    public function test_inspector_detects_modern_schema_without_optional_tables(): void
    {
        $this->createCharactersTable('reputation');
        $this->createClanTable();

        $profile = app(MobiusGameSchemaInspector::class)->inspect(DB::connection(), 'Classic 3.5 Tales Untold');

        $this->assertSame(MobiusGameSchemaProfile::MODERN, $profile->name);
        $this->assertSame('reputation', $profile->reputationColumn);
        $this->assertFalse($profile->heroesAvailable);
        $this->assertFalse($profile->castlesAvailable);
    }

    public function test_chronicle_selects_reputation_column_when_both_exist(): void
    {
        $this->createCharactersTable('both');
        $this->createClanTable();
        $inspector = app(MobiusGameSchemaInspector::class);

        $legacy = $inspector->inspect(DB::connection(), 'High Five');
        $modern = $inspector->inspect(DB::connection(), 'Shine Maker');

        $this->assertSame('karma', $legacy->reputationColumn);
        $this->assertSame('reputation', $modern->reputationColumn);
    }

    public function test_inspector_rejects_missing_required_character_column(): void
    {
        $this->createCharactersTable('karma', ['char_name']);
        $this->createClanTable();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('characters table is missing required columns: char_name');

        app(MobiusGameSchemaInspector::class)->inspect(DB::connection(), 'Interlude');
    }

    public function test_inspector_accepts_missing_optional_character_creation_column(): void
    {
        $this->createCharactersTable('karma', ['createDate']);
        $this->createClanTable();

        $profile = app(MobiusGameSchemaInspector::class)->inspect(DB::connection(), 'Interlude');

        $this->assertSame(MobiusGameSchemaProfile::LEGACY, $profile->name);
        $this->assertSame('karma', $profile->reputationColumn);
    }

    public function test_inspector_rejects_missing_required_clan_table(): void
    {
        $this->createCharactersTable('karma');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('required clan_data table');

        app(MobiusGameSchemaInspector::class)->inspect(DB::connection(), 'Interlude');
    }

    /** @param list<string> $omitted */
    private function createCharactersTable(string $reputationMode, array $omitted = []): void
    {
        Schema::create('characters', function (Blueprint $table) use ($reputationMode, $omitted): void {
            $columns = [
                'account_name',
                'charId',
                'char_name',
                'level',
                'exp',
                'classid',
                'race',
                'sex',
                'title',
                'online',
                'onlinetime',
                'accesslevel',
                'deletetime',
                'pvpkills',
                'pkkills',
                'nobless',
                'clanid',
                'lastAccess',
                'createDate',
            ];

            foreach ($columns as $column) {
                if (! in_array($column, $omitted, true)) {
                    $table->string($column)->nullable();
                }
            }

            if (in_array($reputationMode, ['karma', 'both'], true)) {
                $table->integer('karma')->default(0);
            }
            if (in_array($reputationMode, ['reputation', 'both'], true)) {
                $table->integer('reputation')->default(0);
            }
        });
    }

    private function createClanTable(): void
    {
        Schema::create('clan_data', function (Blueprint $table): void {
            foreach (['clan_id', 'clan_name', 'clan_level', 'reputation_score', 'hasCastle', 'leader_id'] as $column) {
                $table->string($column)->nullable();
            }
        });
    }

    private function createHeroesTable(): void
    {
        Schema::create('heroes', function (Blueprint $table): void {
            foreach (['charId', 'class_id', 'count', 'played', 'claimed'] as $column) {
                $table->string($column)->nullable();
            }
        });
    }

    private function createCastleTable(): void
    {
        Schema::create('castle', function (Blueprint $table): void {
            $table->string('id')->nullable();
            $table->string('name')->nullable();
        });
    }
}
