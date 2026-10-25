<?php

namespace Tests\Unit\UniversalDrivers;

use App\Services\GameWorld\MobiusGameSchemaInspector;
use App\Services\GameWorld\RusacisGameSchemaInspector;
use App\Services\Servers\ServerDriverRegistry;
use App\Support\UniversalDrivers\BundledProfileCatalog;
use App\Support\UniversalDrivers\ProfileValidationException;
use App\Support\UniversalDrivers\ProfileValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProfileValidatorTest extends TestCase
{
    public function test_bundled_profiles_describe_existing_registry_schema_without_switching_it(): void
    {
        $profiles = (new BundledProfileCatalog)->all();
        $registry = app(ServerDriverRegistry::class);
        $this->assertCount(5, $profiles);
        foreach ($registry->loginDrivers() as $key => $driver) {
            foreach ($driver['requirements'] as $i => $requirement) {
                $mapped = $profiles['login:'.$key]['config']['requirements'][$i];
                $this->assertSame($requirement['table'], $mapped['table']);
                $this->assertSame($requirement['required'], $mapped['required']);
                // Legacy requirements treat metadata as mandatory. Read-only
                // profiles separate optional metadata without losing fields.
                $this->assertEqualsCanonicalizing($requirement['columns'], array_merge($mapped['columns'], $mapped['optional_columns'] ?? []));
            }
        }
        foreach ($registry->gameDrivers() as $key => $driver) {
            $this->assertSame($driver['requirements'], $profiles['game:'.$key]['config']['requirements']);
        }
        $this->assertSame(MobiusGameSchemaInspector::requirements(), $profiles['game:l2j_mobius']['config']['requirements']);
        $this->assertSame(RusacisGameSchemaInspector::requirements(), $profiles['game:rusacis']['config']['requirements']);
        $this->assertSame('bcrypt_2a', $profiles['login:rusacis']['config']['password']['scheme']);
        $this->assertSame('rusacis_hero_active', $profiles['game:rusacis']['config']['characters']['fields']['hero']['operator']);
        $this->assertSame('mobius_chronicle', $profiles['game:l2j_mobius']['config']['characters']['fields']['reputation']['preference']);
    }

    public function test_every_bundled_profile_roundtrips_without_connections(): void
    {
        $validator = new ProfileValidator;
        foreach ((new BundledProfileCatalog)->all() as $profile) {
            $encoded = json_encode($profile, JSON_THROW_ON_ERROR);
            $this->assertSame($profile, $validator->fromJson($encoded));
        }
    }

    #[DataProvider('maliciousJson')]
    public function test_unsafe_import_is_rejected(string $json): void
    {
        $this->expectException(ProfileValidationException::class);
        (new ProfileValidator)->fromJson($json);
    }

    /** @return iterable<string, array{string}> */
    public static function maliciousJson(): iterable
    {
        yield 'unparseable' => ['{invalid'];
        yield 'root list' => ['[]'];
        yield 'unknown format' => ['{"format_version":999}'];
        yield 'empty' => [''];
        yield 'oversize' => [str_repeat('X', 262145)];
        yield 'code field' => ['{"id":"a","name":"a","type":"login","format_version":1,"config":{"php":"system"}}'];
    }

    public function test_unsafe_nested_operations_and_identifiers_are_rejected(): void
    {
        $profiles = (new BundledProfileCatalog)->all();
        $game = $profiles['game:rusacis'];
        $login = $profiles['login:rusacis'];
        foreach ([
            static function (array &$p): void { $p['config']['characters']['table'] = 'characters; DROP TABLE accounts'; },
            static function (array &$p): void { $p['config']['characters']['fields']['hero']['operator'] = 'eval'; },
            static function (array &$p): void { $p['config']['relations']['clan']['sql'] = 'DROP DATABASE'; },
            static function (array &$p): void { $p['config']['filters'][0]['op'] = 'raw'; },
            static function (array &$p): void { $p['config']['database']['collation'] = 'utf8mb4_malicious'; },
        ] as $modify) {
            $copy = $game;
            $modify($copy);
            try {
                (new ProfileValidator)->validate($copy);
                $this->fail('Unsafe profile accepted');
            } catch (ProfileValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $login['config']['password']['scheme'] = 'password_hash_php';
        $this->expectException(ProfileValidationException::class);
        (new ProfileValidator)->validate($login);
    }
}
