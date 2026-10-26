<?php

namespace Tests\Unit\UniversalDrivers;

use App\Support\UniversalDrivers\GameCharacterFilters;
use App\Support\UniversalDrivers\GameProfileEditor;
use App\Support\UniversalDrivers\ProfileEditorDraft;
use App\Support\UniversalDrivers\ProfileValidationException;
use App\Support\UniversalDrivers\ProfileValidator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GameCharacterFiltersTest extends TestCase
{
    public function test_related_filters_match_the_same_row_without_duplicates_or_ownership_bypass(): void
    {
        $database = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);
        $database->getSchemaBuilder()->create('players', function (Blueprint $table): void {
            $table->integer('id');
            $table->string('owner');
            $table->integer('deleted')->nullable();
        });
        $database->getSchemaBuilder()->create('flags', function (Blueprint $table): void {
            $table->integer('char_id');
            $table->string('kind');
            $table->integer('value')->nullable();
        });
        $database->table('players')->insert([
            ['id' => 1, 'owner' => 'alice', 'deleted' => null],
            ['id' => 2, 'owner' => 'alice', 'deleted' => 0],
            ['id' => 3, 'owner' => 'bob', 'deleted' => 0],
            ['id' => 4, 'owner' => 'alice', 'deleted' => 0],
            ['id' => 5, 'owner' => 'alice', 'deleted' => 100],
        ]);
        $database->table('flags')->insert([
            ['char_id' => 1, 'kind' => 'access', 'value' => 0],
            ['char_id' => 1, 'kind' => 'access', 'value' => 0],
            ['char_id' => 2, 'kind' => 'access', 'value' => 127],
            ['char_id' => 2, 'kind' => 'other', 'value' => 0],
            ['char_id' => 3, 'kind' => 'access', 'value' => 0],
            ['char_id' => 5, 'kind' => 'access', 'value' => 0],
        ]);
        $columns = ['players' => ['id', 'owner', 'deleted'], 'flags' => ['char_id', 'kind', 'value']];
        $config = ['characters' => ['table' => 'players'], 'filters' => [
            ['column' => 'deleted', 'op' => 'zero_or_null'],
            self::related('kind', 'eq', 'access'), self::related('value', 'eq', 0),
        ]];
        foreach (['players', 'c'] as $reference) {
            $query = $database->table($reference === 'players' ? 'players' : 'players as c')->where($reference.'.owner', 'alice');
            GameCharacterFilters::apply($query, $config, $columns, $reference);
            $this->assertSame([1], $query->pluck($reference.'.id')->all());
        }
        $config['filters'] = [self::related('kind', 'eq', "access' OR 1=1 --")];
        $query = $database->table('players');
        GameCharacterFilters::apply($query, $config, $columns, 'players');
        $this->assertFalse($query->exists());
        $this->assertContains("access' OR 1=1 --", $query->getBindings());

        $config['filters'] = [self::related('value', 'gt', 100)];
        $query = $database->table('players');
        GameCharacterFilters::apply($query, $config, $columns, 'players');
        $this->assertSame([2], $query->pluck('id')->all());
        $config['filters'] = [self::related('value', 'zero_or_null')];
        $query = $database->table('players');
        GameCharacterFilters::apply($query, $config, $columns, 'players');
        $this->assertSame([1, 2, 3, 5], $query->pluck('id')->all());
    }

    public function test_editor_declares_both_tables_and_renames_the_local_link(): void
    {
        $profile = (new ProfileEditorDraft)->blank('game');
        $profile['id'] = 'linked';
        $profile['name'] = 'Linked';
        $profile['config']['filters'] = [self::related('value', 'eq', 0)];
        $profile['config']['filters'][0]['link']['local_column'] = 'charId';
        $original = $profile['config'];
        $profile['config']['characters']['table'] = 'players';
        $profile['config']['characters']['fields']['id']['column'] = 'id';
        $result = (new GameProfileEditor)->prepare($profile, $original);
        (new ProfileValidator)->validate($result);
        $rules = array_column($result['config']['requirements'], null, 'table');
        $this->assertContains('id', $rules['players']['columns']);
        $this->assertSame(['value', 'char_id'], $rules['flags']['columns']);
        $this->assertTrue($rules['flags']['required']);
        $this->assertSame('id', $result['config']['filters'][0]['link']['local_column']);

        $result['config']['requirements'] = array_values(array_filter($result['config']['requirements'], fn (array $rule): bool => $rule['table'] !== 'flags'));
        $this->expectException(ProfileValidationException::class);
        (new ProfileValidator)->validate($result);
    }

    #[DataProvider('invalidFilters')]
    public function test_unsafe_or_incomplete_sources_are_rejected(array $filter): void
    {
        $profile = (new ProfileEditorDraft)->blank('game');
        $profile['config']['filters'] = [$filter];
        $this->expectException(ProfileValidationException::class);
        (new ProfileValidator)->validateGameFilters($profile['config']);
    }

    public static function invalidFilters(): iterable
    {
        yield 'missing link' => [['table' => 'flags', 'column' => 'value', 'op' => 'eq', 'value' => 0]];
        yield 'injected table' => [self::related('value', 'eq', 0) + ['unused' => true]];
        $filter = self::related('value', 'eq', 0);
        $filter['table'] = 'flags; DROP TABLE characters';
        yield 'SQL source' => [$filter];
        $filter = self::related('value', 'eq', 0);
        $filter['link']['foreign_column'] = 'char_id OR 1=1';
        yield 'SQL link' => [$filter];
        $filter = self::related('value', 'eq', 0);
        $filter['link']['local_column'] = '';
        yield 'empty link' => [$filter];
        yield 'null table' => [['table' => null, 'column' => 'id', 'op' => 'eq', 'value' => 0]];
        yield 'primary link' => [['table' => 'characters', 'column' => 'id', 'op' => 'eq', 'value' => 0, 'link' => ['local_column' => 'charId', 'foreign_column' => 'char_id']]];
    }

    public function test_missing_related_schema_is_never_silently_ignored(): void
    {
        $database = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);
        $query = $database->table('players');
        $this->expectException(ProfileValidationException::class);
        GameCharacterFilters::apply($query, ['characters' => ['table' => 'players'], 'filters' => [self::related('value', 'eq', 0)]], ['players' => ['id']], 'players');
    }

    private static function related(string $column, string $op, mixed $value = null): array
    {
        $filter = ['table' => 'flags', 'column' => $column, 'op' => $op, 'link' => ['local_column' => 'id', 'foreign_column' => 'char_id']];
        if ($op !== 'zero_or_null') {
            $filter['value'] = $value;
        }

        return $filter;
    }
}
