<?php

namespace Tests\Feature\UniversalDrivers;

use App\Auth\AdminRole;
use App\Contracts\ExternalDatabaseConnectionTester;
use App\Livewire\Admin\UniversalDriverManager;
use App\Models\Admin;
use App\Models\GameServer;
use App\Services\Servers\UniversalDriverActivation;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleValidator;
use App\Support\UniversalDrivers\DriverExtensionAccess;
use App\Support\UniversalDrivers\DriverExtensionCatalog;
use App\Support\UniversalDrivers\DriverExtensionRequirements;
use App\Support\UniversalDrivers\DriverExtensionSchemaVerifier;
use App\Support\UniversalDrivers\DriverProfileCatalog;
use App\Support\UniversalDrivers\ProfileValidationException;
use App\Support\UniversalDrivers\ProfileValidator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Fakes\FakeExternalDatabaseConnectionTester;
use Tests\TestCase;

final class DriverExtensionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function premiumManifest(bool $required = true): array
    {
        return [
            'schema' => 1, 'id' => 'premium', 'name' => 'Premium', 'version' => '1.0.0',
            'driver_extensions' => [[
                'key' => 'premium', 'type' => 'game', 'required' => $required,
                'title' => ['ru' => 'Премиум-аккаунт', 'en' => 'Premium account'],
                'description' => ['en' => 'Where the premium end date is stored.'],
                'fields' => [
                    ['name' => 'table', 'kind' => 'table', 'required' => true, 'label' => ['ru' => 'Таблица премиума', 'en' => 'Premium table']],
                    ['name' => 'until_column', 'kind' => 'column', 'required' => true, 'label' => ['ru' => 'Столбец окончания', 'en' => 'End column']],
                    ['name' => 'unit', 'kind' => 'choice', 'label' => ['en' => 'Unit'], 'options' => ['days', 'seconds']],
                ],
            ]],
        ];
    }

    /** A catalogue that sees one installed module, without touching the modules directory. */
    private function catalogWithPremium(bool $required = true): DriverExtensionCatalog
    {
        $manifest = $this->premiumManifest($required);

        return new class(app(ModuleManager::class), $manifest) extends DriverExtensionCatalog
        {
            /** @param array<string, mixed> $manifest */
            public function __construct(ModuleManager $modules, private readonly array $manifest)
            {
                parent::__construct($modules);
            }

            public function declarations(?string $type = null): array
            {
                return DriverExtensionCatalog::fromManifest('premium', 'Premium', $this->manifest, $type);
            }
        };
    }

    public function test_a_manifest_declaration_is_normalised_with_labels_in_the_current_language(): void
    {
        app()->setLocale('ru');
        $declarations = DriverExtensionCatalog::fromManifest('premium', 'Premium', $this->premiumManifest());

        $this->assertCount(1, $declarations);
        $this->assertSame('Премиум-аккаунт', $declarations[0]['title']);
        $this->assertSame('Таблица премиума', $declarations[0]['fields'][0]['label']);
        // A missing translation falls back to English, then to any text.
        $this->assertSame('Unit', $declarations[0]['fields'][2]['label']);
        $this->assertSame(['days', 'seconds'], $declarations[0]['fields'][2]['options']);
        $this->assertSame([], DriverExtensionCatalog::fromManifest('premium', 'Premium', $this->premiumManifest(), 'login'));
    }

    public function test_module_validator_accepts_the_declaration_and_rejects_broken_ones(): void
    {
        $directory = storage_path('framework/testing/driver-extension-modules-'.bin2hex(random_bytes(4)));
        $files = new Filesystem;
        $write = function (array $manifest) use ($directory, $files): array {
            $files->deleteDirectory($directory.'/premium');
            $files->ensureDirectoryExists($directory.'/premium');
            $files->put($directory.'/premium/module.json', json_encode($manifest + ['cms_min' => '0.50.0'], JSON_THROW_ON_ERROR));

            return (new ModuleValidator(new Filesystem))->inspect('premium', $directory);
        };

        try {
            $valid = $this->premiumManifest();
            unset($valid['schema'], $valid['id'], $valid['name'], $valid['version']);
            $base = ['schema' => 1, 'id' => 'premium', 'name' => 'Premium', 'version' => '1.0.0', 'author' => 'T', 'description' => 'T'];

            $good = $write($base + $valid);
            $this->assertSame([], array_filter($good['errors'], static fn (string $error): bool => str_contains($error, 'driver_extensions')));

            $broken = $valid;
            $broken['driver_extensions'][0]['fields'][2]['options'] = [];
            $bad = $write($base + $broken);
            $this->assertNotEmpty(array_filter($bad['errors'], static fn (string $error): bool => str_contains($error, 'driver_extensions')));

            $broken = $valid;
            $broken['driver_extensions'][0]['type'] = 'shop';
            $bad = $write($base + $broken);
            $this->assertNotEmpty(array_filter($bad['errors'], static fn (string $error): bool => str_contains($error, 'driver_extensions')));
        } finally {
            $files->deleteDirectory($directory);
        }
    }

    public function test_profile_format_carries_module_answers_and_rejects_a_malformed_shape(): void
    {
        $profile = app(DriverProfileCatalog::class)->find('game', 'rusacis')['profile'];
        $profile['config']['extensions'] = ['premium' => ['premium' => ['table' => 'account_premium', 'until_column' => 'premium_end']]];
        (new ProfileValidator)->validate($profile);
        $this->addToAssertionCount(1);

        foreach ([
            ['Premium' => ['premium' => ['table' => 'x']]],
            ['premium' => ['Premium' => ['table' => 'x']]],
            ['premium' => ['premium' => ['Table' => 'x']]],
            ['premium' => ['premium' => ['table' => ['nested']]]],
            ['premium' => ['premium' => ['table' => str_repeat('x', 129)]]],
            ['premium' => ['premium' => ['table' => "line\nbreak"]]],
        ] as $extensions) {
            $bad = $profile;
            $bad['config']['extensions'] = $extensions;
            try {
                (new ProfileValidator)->validate($bad);
                $this->fail('A malformed extensions block was accepted.');
            } catch (ProfileValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_validate_and_clean_follow_the_declared_field_kinds(): void
    {
        $catalog = $this->catalogWithPremium();

        $errors = $catalog->validate('game', ['premium' => ['premium' => ['table' => 'bad name;', 'until_column' => 'premium_end', 'unit' => 'weeks']]]);
        $this->assertArrayHasKey('draft.config.extensions.premium.premium.table', $errors);
        $this->assertArrayHasKey('draft.config.extensions.premium.premium.unit', $errors);
        $this->assertArrayNotHasKey('draft.config.extensions.premium.premium.until_column', $errors);
        $this->assertSame([], $catalog->validate('game', ['premium' => ['premium' => ['table' => 'account_premium', 'unit' => 'days']]]));

        $this->assertSame(
            ['premium' => ['premium' => ['table' => 'account_premium']], 'other' => ['x' => ['y' => 'kept']]],
            $catalog->clean(['premium' => ['premium' => ['table' => ' account_premium ', 'until_column' => '', 'unit' => '']], 'other' => ['x' => ['y' => 'kept']], 'empty' => ['x' => ['y' => '']]]),
        );
    }

    public function test_missing_lists_only_required_fields_of_required_extensions(): void
    {
        $declarations = DriverExtensionCatalog::fromManifest('premium', 'Premium', $this->premiumManifest());
        $profile = ['config' => ['extensions' => ['premium' => ['premium' => ['table' => 'account_premium']]]]];

        $missing = DriverExtensionCatalog::missing($profile, $declarations);
        $this->assertSame(['until_column'], array_column($missing, 'field'));

        $this->assertSame([], DriverExtensionCatalog::missing(['config' => []], DriverExtensionCatalog::fromManifest('premium', 'Premium', $this->premiumManifest(required: false))));
    }

    public function test_a_module_is_blocked_until_the_driver_in_use_has_what_it_declared(): void
    {
        app()->setLocale('en');
        GameServer::factory()->create(['name' => 'Main world', 'driver' => 'rusacis']);
        // A simulated real DB probe. Without it, a catalog entry alone must
        // never authorize a module that needs game database columns.
        $fake = new FakeExternalDatabaseConnectionTester;
        $this->app->instance(ExternalDatabaseConnectionTester::class, $fake);
        $this->app->instance(DriverExtensionCatalog::class, $this->catalogWithPremium());
        $requirements = app(DriverExtensionRequirements::class);

        $problems = array_values(array_filter(
            $requirements->problems('premium', 'Premium', $this->premiumManifest()),
            static fn (string $problem): bool => str_contains($problem, 'Main world'),
        ));
        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Premium table', $problems[0]);
        $this->assertStringContainsString('End column', $problems[0]);
        $this->assertStringContainsString('Servers → Drivers', $problems[0]);

        // A saved override is a draft until explicitly applied to this server.
        $catalog = app(DriverProfileCatalog::class);
        $profile = $catalog->find('game', 'rusacis')['profile'];
        $profile['config']['extensions'] = ['premium' => ['premium' => ['table' => 'account_premium', 'until_column' => 'premium_end']]];
        $catalog->save($profile);
        $server = GameServer::query()->where('name', 'Main world')->firstOrFail();
        $this->assertSame([], app(DriverExtensionAccess::class)->values($server, 'premium', 'premium'));
        $this->assertNotEmpty($requirements->problems('premium', 'Premium', $this->premiumManifest()));
        app(UniversalDriverActivation::class)->apply($server);
        $this->assertSame([], array_values(array_filter(
            $requirements->problems('premium', 'Premium', $this->premiumManifest()),
            static fn (string $problem): bool => str_contains($problem, 'Main world'),
        )));

        // And the module reads its answers for one server at run time.
        $this->assertSame('account_premium', app(DriverExtensionAccess::class)->values($server, 'premium', 'premium')['table']);
        $this->assertSame([], app(DriverExtensionAccess::class)->values($server, 'premium', 'unknown'));
        $this->assertSame([['table' => 'account_premium', 'columns' => ['premium_end'], 'required' => true]], $fake->requirements);
        // Once the real schema probe fails, even a formerly enabled module
        // receives no mappings, and enabling it again is rejected.
        $fake->report['compatible'] = false;
        $this->assertSame([], app(DriverExtensionAccess::class)->values($server, 'premium', 'premium'));
        $this->assertNotEmpty($requirements->problems('premium', 'Premium', $this->premiumManifest()));

        // An optional extension never blocks.
        $this->assertSame([], $requirements->problems('premium', 'Premium', $this->premiumManifest(required: false)));
    }

    public function test_the_editor_shows_installed_module_fields_and_saves_the_answers(): void
    {
        app()->setLocale('ru');
        $this->app->instance(DriverExtensionCatalog::class, $this->catalogWithPremium());
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');

        $component = Livewire::test(UniversalDriverManager::class)
            ->call('createProfile', 'game', 'rusacis')
            ->set('draft.id', 'with-premium')
            ->set('draft.name', 'With premium')
            ->call('nextGameStep', 'features')
            ->assertSee('Премиум-аккаунт')
            ->assertSee('Таблица премиума')
            ->set('draft.config.extensions.premium.premium.table', 'bad name;')
            ->call('saveProfile')
            ->assertHasErrors(['draft.config.extensions.premium.premium.table']);

        $component->set('draft.config.extensions.premium.premium.table', 'account_premium')
            ->set('draft.config.extensions.premium.premium.until_column', 'premium_end')
            ->call('saveProfile')->assertHasNoErrors()->assertSet('editing', false);

        $saved = app(DriverProfileCatalog::class)->find('game', 'with-premium')['profile'];
        $this->assertSame(
            ['premium' => ['premium' => ['table' => 'account_premium', 'until_column' => 'premium_end']]],
            $saved['config']['extensions'],
        );
    }

    public function test_a_driver_without_module_answers_stays_free_of_an_extensions_block(): void
    {
        $this->app->instance(DriverExtensionCatalog::class, $this->catalogWithPremium(required: false));
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Owner]), 'admin');

        Livewire::test(UniversalDriverManager::class)
            ->call('createProfile', 'game', 'rusacis')
            ->set('draft.id', 'plain-driver')
            ->set('draft.name', 'Plain driver')
            ->call('saveProfile')->assertHasNoErrors();

        $this->assertArrayNotHasKey('extensions', app(DriverProfileCatalog::class)->find('game', 'plain-driver')['profile']['config']);
    }

    public function test_incomplete_optional_groups_and_unsafe_or_ambiguous_identifiers_are_refused_before_queries(): void
    {
        $fake = new FakeExternalDatabaseConnectionTester;
        $verifier = new DriverExtensionSchemaVerifier($fake);
        $declaration = DriverExtensionCatalog::fromManifest('premium', 'Premium', $this->premiumManifest(required: false))[0];
        $server = GameServer::factory()->create(['driver' => 'rusacis']);
        $profile = app(DriverProfileCatalog::class)->find('game', 'rusacis')['profile'];
        foreach ([
            ['table' => 'premium_table'],
            ['table' => 'accounts; DROP TABLE accounts', 'until_column' => 'end_time'],
            ['table' => 'premium_table', 'until_column' => 'end_time` OR 1=1'],
        ] as $values) {
            $profile['config']['extensions'] = ['premium' => ['premium' => $values]];
            $this->assertFalse($verifier->compatible($server, $profile, $declaration));
        }
        $declaration['fields'][] = ['name' => 'second_table', 'kind' => 'table', 'required' => true];
        $profile['config']['extensions'] = ['premium' => ['premium' => [
            'table' => 'premium_table', 'second_table' => 'another_table', 'until_column' => 'end_time',
        ]]];
        $this->assertFalse($verifier->compatible($server, $profile, $declaration));
        $this->assertSame([], $fake->requirements);
    }
}
