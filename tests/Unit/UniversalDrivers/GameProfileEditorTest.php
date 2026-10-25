<?php

namespace Tests\Unit\UniversalDrivers;

use App\Support\UniversalDrivers\BundledProfileCatalog;
use App\Support\UniversalDrivers\GameProfileEditor;
use App\Support\UniversalDrivers\ProfileValidator;
use PHPUnit\Framework\TestCase;

final class GameProfileEditorTest extends TestCase
{
    public function test_key_value_fields_do_not_advertise_unsupported_world_rankings(): void
    {
        $profile = (new BundledProfileCatalog)->all()['game:rusacis'];
        $old = $profile['config'];
        $profile['config']['characters']['fields']['title'] = ['kind' => 'eav', 'relation' => 'stats', 'key_column' => 'param', 'value_column' => 'value', 'key_value' => 'title'];
        $profile['config']['relations']['stats'] = ['table' => 'character_stats', 'local_column' => 'obj_Id', 'foreign_column' => 'char_id', 'cardinality' => 'many', 'join' => 'left'];
        $result = (new GameProfileEditor)->prepare($profile, $old);
        (new ProfileValidator)->validate($result);
        $this->assertSame(['characters', 'castles'], $result['config']['capabilities']);
        $this->assertSame([], $result['config']['rankings']);
        $rules = array_column($result['config']['requirements'], null, 'table');
        $this->assertSame(['char_id', 'param', 'value'], $rules['character_stats']['columns']);
    }

    public function test_bundled_mappings_remain_valid_and_optional_world_tables_stay_optional(): void
    {
        foreach ((new BundledProfileCatalog)->all() as $profile) {
            if ($profile['type'] !== 'game') {
                continue;
            }
            $result = (new GameProfileEditor)->prepare($profile, $profile['config']);
            (new ProfileValidator)->validate($result);
            $this->assertSame($profile['config']['characters'], $result['config']['characters']);
            $this->assertSame($profile['config']['features'], $result['config']['features']);
            $rules = array_column($result['config']['requirements'], null, 'table');
            $this->assertFalse($rules['heroes']['required']);
            $this->assertFalse($rules['castle']['required']);
            $this->assertNotContains('character_rescue', $result['config']['capabilities']);
            if ($profile['id'] === 'l2j_mobius') {
                $this->assertContains('createDate', $rules['characters']['optional_columns']);
                $this->assertContains('karma', $rules['characters']['any_columns']);
            }
        }
    }

    public function test_renaming_character_table_and_fields_updates_checks_and_rankings(): void
    {
        $profile = (new BundledProfileCatalog)->all()['game:rusacis'];
        $old = $profile['config'];
        $profile['config']['characters']['table'] = 'players';
        $profile['config']['characters']['fields']['id']['column'] = 'player_id';
        $profile['config']['characters']['fields']['level']['column'] = 'player_level';
        $result = (new GameProfileEditor)->prepare($profile, $old);
        (new ProfileValidator)->validate($result);
        $rules = array_column($result['config']['requirements'], null, 'table');
        $this->assertArrayNotHasKey('characters', $rules);
        $this->assertContains('player_id', $rules['players']['columns']);
        $this->assertNotContains('obj_Id', $rules['players']['columns']);
        $this->assertSame('player_level', $result['config']['rankings']['level']['column']);
        $this->assertSame('player_level', $result['config']['rankings']['pvp']['secondary']);
        $this->assertSame('players', $result['config']['relations']['leader']['table']);
        $this->assertSame('player_id', $result['config']['relations']['heroes']['local_column']);
        $this->assertSame('player_id', $result['config']['relations']['leader']['foreign_column']);
    }
}
