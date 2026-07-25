<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\ModuleState;
use App\Support\KaevCMS;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ModuleFoundationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $createdModules = [];

    /** @var list<string> */
    private array $createdTables = [];

    protected function tearDown(): void
    {
        $files = new Filesystem;

        foreach ($this->createdModules as $module) {
            $files->deleteDirectory(base_path('modules/'.$module));
        }

        foreach ($this->createdTables as $table) {
            Schema::dropIfExists($table);
        }

        if (app()->bound(ModuleManager::class)) {
            app(ModuleManager::class)->refresh();
        }

        parent::tearDown();
    }

    public function test_enable_refreshes_stale_migration_tracking_state(): void
    {
        $id = 'migration-cache-fixture';
        $root = $this->createModule($id, [
            'name' => 'Migration Cache Fixture',
            'migrations' => 'database/migrations',
        ]);
        $migration = '2026_07_21_000001_create_cache_fixture.php';
        $table = 'module_migration_cache_fixture';
        $this->createTableMigration($root, $migration, $table);

        Schema::drop('cms_module_migrations');

        $modules = app(ModuleManager::class);
        $modules->refresh();
        $stale = $modules->inspect($id);

        $this->assertFalse($stale['migration_tracking_available']);

        Schema::create('cms_module_migrations', function (Blueprint $table): void {
            $table->id();
            $table->string('module_id', 100)->index();
            $table->string('migration', 190);
            $table->char('checksum', 64);
            $table->unsignedInteger('batch');
            $table->timestamp('ran_at');
            $table->unique(['module_id', 'migration']);
        });

        $enabled = $modules->enable($id);

        $this->assertTrue($enabled['enabled']);
        $this->assertTrue($enabled['migration_tracking_available']);
        $this->assertTrue(Schema::hasTable($table));
        $this->assertDatabaseHas('cms_module_migrations', [
            'module_id' => $id,
            'migration' => $migration,
        ]);
    }

    public function test_manifest_is_strictly_validated_before_a_module_can_be_enabled(): void
    {
        $this->createModule('foundation-fixture', [
            'description' => 'Valid module fixture.',
            'cms_min' => '0.24.0',
            'cms_max' => KaevCMS::version(),
        ]);

        $module = app(ModuleManager::class)->inspect('foundation-fixture');

        $this->assertTrue($module['valid'], implode(PHP_EOL, $module['errors']));
        $this->assertTrue($module['compatible'], implode(PHP_EOL, $module['errors']));
        $this->assertSame('disabled', $module['status']);
        $this->assertTrue($module['can_enable']);

        $this->createModule('unsafe-fixture', [
            'bootstrap' => '../bootstrap.php',
        ]);

        $unsafe = app(ModuleManager::class)->inspect('unsafe-fixture');

        $this->assertFalse($unsafe['valid']);
        $this->assertFalse($unsafe['can_enable']);
        $this->assertContains(
            __('The module :field file was not found or is unsafe.', ['field' => 'bootstrap']),
            $unsafe['errors'],
        );

        $this->createModule('malformed-fixture', [
            'name' => str_repeat('M', 121),
            'version' => ['not', 'text'],
            'description' => ['not', 'text'],
            'cms_min' => 24,
            'unexpected' => true,
        ]);

        $malformed = app(ModuleManager::class)->inspect('malformed-fixture');

        $this->assertFalse($malformed['valid']);
        $this->assertContains(
            __('The :field field exceeds the maximum length of :max characters.', ['field' => 'name', 'max' => 120]),
            $malformed['errors'],
        );
        $this->assertContains(
            __('The module version must use semantic versioning.'),
            $malformed['errors'],
        );
        $this->assertContains(
            __('The :field field must contain text.', ['field' => 'description']),
            $malformed['errors'],
        );
        $this->assertContains(
            __('The :field field must contain a valid CMS version.', ['field' => 'cms_min']),
            $malformed['errors'],
        );
        $this->assertContains(
            __('Unknown module.json field: :field.', ['field' => 'unexpected']),
            $malformed['errors'],
        );

        $this->createModule('future-fixture', ['cms_min' => '99.0.0']);
        $future = app(ModuleManager::class)->inspect('future-fixture');
        $this->assertTrue($future['valid']);
        $this->assertFalse($future['compatible']);
        $this->assertSame('incompatible', $future['status']);
        $this->assertFalse($future['can_enable']);

        $this->createModule('admin');
        $reserved = app(ModuleManager::class)->inspect('admin');
        $this->assertFalse($reserved['valid']);
        $this->assertContains(__('This module identifier is reserved by KaevCMS.'), $reserved['errors']);
    }

    public function test_owner_manages_modules_administrator_is_read_only_and_editor_is_denied(): void
    {
        $this->createModule('lifecycle-fixture', [
            'name' => 'Lifecycle Fixture',
        ]);

        $owner = $this->createAdmin(AdminRole::Owner, 'owner@example.test');
        $administrator = $this->createAdmin(AdminRole::Administrator, 'administrator@example.test');
        $editor = $this->createAdmin(AdminRole::Editor, 'editor@example.test');

        $this->actingAs($administrator, 'admin')
            ->get('/admin/modules')
            ->assertOk()
            ->assertSee('Lifecycle Fixture')
            ->assertSee(__('Read-only mode'));

        $this->actingAs($administrator, 'admin')
            ->post('/admin/modules/lifecycle-fixture/enable')
            ->assertForbidden();

        $this->actingAs($editor, 'admin')
            ->get('/admin/modules')
            ->assertForbidden();

        $this->actingAs($owner, 'admin')
            ->post('/admin/modules/lifecycle-fixture/enable')
            ->assertRedirect(route('admin.modules.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('cms_modules', [
            'id' => 'lifecycle-fixture',
            'version' => '1.0.0',
            'enabled' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'category' => 'module',
            'action' => 'module.enabled',
            'target_name' => 'lifecycle-fixture',
        ]);

        $this->actingAs($owner, 'admin')
            ->delete('/admin/modules/lifecycle-fixture/disable')
            ->assertRedirect(route('admin.modules.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('cms_modules', [
            'id' => 'lifecycle-fixture',
            'enabled' => false,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'category' => 'module',
            'action' => 'module.disabled',
            'target_name' => 'lifecycle-fixture',
        ]);
    }

    public function test_enabled_module_registers_bootstrap_views_translations_and_scoped_routes(): void
    {
        $id = 'runtime-fixture';
        $root = $this->createModule($id, [
            'name' => 'Runtime Fixture',
            'namespace' => 'KaevCMS\\Modules\\RuntimeFixture\\',
            'autoload' => 'src',
            'bootstrap' => 'bootstrap.php',
            'views' => 'resources/views',
            'lang' => 'lang',
            'routes' => [
                'web' => 'routes/web.php',
                'admin' => 'routes/admin.php',
            ],
        ]);

        $files = new Filesystem;
        $files->ensureDirectoryExists($root.'/src');
        $files->ensureDirectoryExists($root.'/resources/views');
        $files->ensureDirectoryExists($root.'/lang/ru');
        $files->ensureDirectoryExists($root.'/routes');
        $files->put($root.'/src/RuntimeMarker.php', <<<'PHP'
<?php

namespace KaevCMS\Modules\RuntimeFixture;

final readonly class RuntimeMarker
{
    public function __construct(public string $moduleId) {}
}
PHP);
        $files->put($root.'/bootstrap.php', <<<'PHP'
<?php

use App\Support\Modules\ModuleContext;
use Illuminate\Contracts\Foundation\Application;
use KaevCMS\Modules\RuntimeFixture\RuntimeMarker;

return static function (Application $app, ModuleContext $module): void {
    $app->instance('runtime-fixture.marker', ['module_id' => (new RuntimeMarker($module->id))->moduleId]);
};
PHP);
        $files->put($root.'/resources/views/index.blade.php', <<<'BLADE'
<div data-runtime-module>{{ __('module-runtime-fixture::messages.ready') }}</div>
BLADE);
        $files->put($root.'/lang/ru/messages.php', <<<'PHP'
<?php

return ['ready' => 'Модуль загружен'];
PHP);
        $files->put($root.'/routes/web.php', <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['module' => 'runtime-fixture']))->name('ping');
Route::get('/view', fn () => view('module-runtime-fixture::index'))->name('view');
PHP);
        $files->put($root.'/routes/admin.php', <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/status', fn () => response('runtime-admin-ok'))->name('status');
PHP);

        $modules = app(ModuleManager::class);
        $modules->refresh();
        $modules->enable($id);
        app(ModuleRuntime::class)->bootModule($modules->inspect($id));

        $marker = app('runtime-fixture.marker');
        $this->assertIsArray($marker);
        $this->assertSame($id, $marker['module_id']);
        $this->assertTrue(Route::has('modules.runtime-fixture.ping'));
        $this->assertTrue(Route::has('admin.module-pages.runtime-fixture.status'));
        $this->assertSame('/modules/runtime-fixture/ping', route('modules.runtime-fixture.ping', absolute: false));
        $this->assertSame(
            '/admin/extensions/runtime-fixture/status',
            route('admin.module-pages.runtime-fixture.status', ['adminPath' => 'admin'], false),
        );
        $this->get('/modules/runtime-fixture/ping')
            ->assertOk()
            ->assertJson(['module' => 'runtime-fixture']);
        $this->get('/modules/runtime-fixture/view')
            ->assertOk()
            ->assertSee('Модуль загружен')
            ->assertSee('data-runtime-module', false);

        $owner = $this->createAdmin(AdminRole::Owner, 'runtime-owner@example.test');
        $administrator = $this->createAdmin(AdminRole::Administrator, 'runtime-administrator@example.test');
        $editor = $this->createAdmin(AdminRole::Editor, 'runtime-editor@example.test');

        $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/runtime-fixture/status')
            ->assertOk()
            ->assertSee('runtime-admin-ok');
        $this->actingAs($administrator, 'admin')
            ->get('/admin/extensions/runtime-fixture/status')
            ->assertOk();
        $this->actingAs($editor, 'admin')
            ->get('/admin/extensions/runtime-fixture/status')
            ->assertForbidden();

        ModuleState::query()->whereKey($id)->update([
            'last_error' => 'boot: RuntimeException',
            'last_error_at' => now(),
        ]);
        $modules->refresh();
        $this->get('/modules/runtime-fixture/ping')->assertStatus(503);

        ModuleState::query()->whereKey($id)->update([
            'last_error' => null,
            'last_error_at' => null,
        ]);
        $modules->refresh();
        $modules->disable($id);

        // Already-registered or stale cached routes must not execute after a
        // module is disabled, replaced, damaged or awaiting update approval.
        $this->get('/modules/runtime-fixture/ping')->assertNotFound();
        $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/runtime-fixture/status')
            ->assertNotFound();
    }

    public function test_module_routes_are_excluded_from_core_route_cache_builds(): void
    {
        $id = 'cached-route-fixture';
        $root = $this->createModule($id, [
            'name' => 'Cached Route Fixture',
            'routes' => ['web' => 'routes/web.php'],
        ]);

        $files = new Filesystem;
        $files->ensureDirectoryExists($root.'/routes');
        $files->put($root.'/routes/web.php', <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/cached', fn () => response('cached-module-route'))->name('cached');
PHP);

        $modules = app(ModuleManager::class);
        $modules->refresh();
        $module = $modules->enable($id);
        $routeCount = Route::getRoutes()->count();

        $application = Mockery::mock(Application::class);
        $application->shouldReceive('runningInConsole')->once()->andReturnTrue();
        $previousArguments = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['artisan', 'route:cache'];

        try {
            (new ModuleRuntime($application, $files))->bootModule($module);
        } finally {
            if ($previousArguments === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $previousArguments;
            }
        }

        $this->assertSame($routeCount, Route::getRoutes()->count());
        $this->assertFalse(Route::has('modules.cached-route-fixture.cached'));
    }

    public function test_broken_enabled_module_is_isolated_reported_and_retried_after_fix(): void
    {
        $id = 'broken-runtime-fixture';
        $root = $this->createModule($id, [
            'name' => 'Broken Runtime Fixture',
            'bootstrap' => 'bootstrap.php',
        ]);
        $files = new Filesystem;
        $files->put($root.'/bootstrap.php', "<?php\n\nreturn 'not-callable';\n");

        $modules = app(ModuleManager::class);
        $modules->refresh();
        $modules->enable($id);
        app(ModuleRuntime::class)->bootEnabled($modules);
        $modules->refresh();

        $broken = $modules->inspect($id);
        $this->assertSame('runtime_error', $broken['status']);
        $this->assertStringContainsString('boot: RuntimeException', (string) $broken['last_error']);
        $this->get('/')->assertOk();

        $files->put($root.'/bootstrap.php', <<<'PHP'
<?php

use App\Support\Modules\ModuleContext;
use Illuminate\Contracts\Foundation\Application;

return static function (Application $app, ModuleContext $module): void {
    $app->instance('broken-runtime-fixture.recovered', $module->id);
};
PHP);
        $modules->refresh();
        app(ModuleRuntime::class)->bootEnabled($modules);
        $this->assertFalse(app()->bound('broken-runtime-fixture.recovered'));

        ModuleState::query()->whereKey($id)->update([
            'last_error_at' => now()->subMinutes(2),
        ]);
        $modules->refresh();
        app(ModuleRuntime::class)->bootEnabled($modules);
        $modules->refresh();

        $recovered = $modules->inspect($id);
        $this->assertSame('enabled', $recovered['status']);
        $this->assertNull($recovered['last_error']);
        $this->assertSame($id, app('broken-runtime-fixture.recovered'));
    }

    public function test_changed_module_version_requires_owner_approval_before_runtime_loading(): void
    {
        $id = 'update-fixture';
        $root = $this->createModule($id, ['name' => 'Update Fixture']);
        $modules = app(ModuleManager::class);

        $modules->enable($id);
        $this->assertSame('enabled', $modules->inspect($id)['status']);

        $manifest = json_decode((new Filesystem)->get($root.'/module.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['version'] = '1.1.0';
        (new Filesystem)->put(
            $root.'/module.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        $modules->refresh();

        $pending = $modules->inspect($id);
        $this->assertSame('update_pending', $pending['status']);
        $this->assertTrue($pending['update_available']);
        $this->assertTrue($pending['can_enable']);
        $this->assertSame([], $modules->enabled());

        $modules->enable($id);

        $approved = $modules->inspect($id);
        $this->assertSame('enabled', $approved['status']);
        $this->assertFalse($approved['update_available']);
        $this->assertSame('1.1.0', $approved['stored_version']);
    }

    public function test_enabled_state_with_missing_files_remains_visible_and_can_be_disabled(): void
    {
        ModuleState::query()->create([
            'id' => 'removed-fixture',
            'version' => '1.4.0',
            'enabled' => true,
            'enabled_at' => now(),
        ]);

        $module = app(ModuleManager::class)->inspect('removed-fixture');

        $this->assertSame('missing', $module['status']);
        $this->assertTrue($module['can_disable']);
        $this->assertFalse($module['can_enable']);

        app(ModuleManager::class)->disable('removed-fixture');

        $this->assertDatabaseHas('cms_modules', [
            'id' => 'removed-fixture',
            'enabled' => false,
        ]);
        $this->assertFalse(collect(app(ModuleManager::class)->installed())->contains('id', 'removed-fixture'));
    }

    public function test_module_migrations_are_applied_once_and_disabling_preserves_data(): void
    {
        $id = 'migration-lifecycle-fixture';
        $root = $this->createModule($id, [
            'name' => 'Migration Lifecycle Fixture',
            'migrations' => 'database/migrations',
        ]);
        $table = 'module_lifecycle_fixture_records';
        $this->createTableMigration($root, '2026_07_21_000001_create_lifecycle_records.php', $table);

        $modules = app(ModuleManager::class);
        $modules->refresh();
        $pending = $modules->inspect($id);

        $this->assertSame('install_pending', $pending['status']);
        $this->assertSame(1, $pending['pending_count']);
        $this->assertTrue($pending['can_enable']);

        $enabled = $modules->enable($id);

        $this->assertSame('enabled', $enabled['status']);
        $this->assertSame(1, $enabled['migration_result']['applied_count']);
        $this->assertTrue(Schema::hasTable($table));
        $this->assertDatabaseHas('cms_module_migrations', [
            'module_id' => $id,
            'migration' => '2026_07_21_000001_create_lifecycle_records.php',
        ]);

        DB::table($table)->insert(['value' => 'preserved']);
        $modules->disable($id);

        $this->assertTrue(Schema::hasTable($table));
        $this->assertSame('preserved', DB::table($table)->value('value'));

        $reenabled = $modules->enable($id);

        $this->assertSame(0, $reenabled['migration_result']['applied_count']);
        $this->assertSame(1, DB::table($table)->count());
    }

    public function test_new_migrations_block_runtime_until_owner_applies_them(): void
    {
        $id = 'migration-update-fixture';
        $root = $this->createModule($id, [
            'name' => 'Migration Update Fixture',
            'migrations' => 'database/migrations',
        ]);
        $firstTable = 'module_update_fixture_first';
        $secondTable = 'module_update_fixture_second';
        $this->createTableMigration($root, '2026_07_21_000001_create_first_table.php', $firstTable);

        $modules = app(ModuleManager::class);
        $modules->refresh();
        $modules->enable($id);

        $this->createTableMigration($root, '2026_07_21_000002_create_second_table.php', $secondTable);
        $modules->refresh();
        $pending = $modules->inspect($id);

        $this->assertSame('migration_pending', $pending['status']);
        $this->assertSame(1, $pending['pending_count']);
        $this->assertTrue($pending['enabled']);
        $this->assertSame([], $modules->enabled());

        $resolved = $modules->enable($id);

        $this->assertSame('enabled', $resolved['status']);
        $this->assertSame(1, $resolved['migration_result']['applied_count']);
        $this->assertTrue(Schema::hasTable($secondTable));
    }

    public function test_module_version_approval_applies_new_migrations_before_storing_the_version(): void
    {
        $id = 'migration-version-fixture';
        $root = $this->createModule($id, [
            'name' => 'Migration Version Fixture',
            'migrations' => 'database/migrations',
        ]);
        $firstTable = 'module_version_fixture_first';
        $secondTable = 'module_version_fixture_second';
        $this->createTableMigration($root, '2026_07_21_000001_create_version_first.php', $firstTable);

        $modules = app(ModuleManager::class);
        $modules->refresh();
        $modules->enable($id);

        $manifest = json_decode((new Filesystem)->get($root.'/module.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest['version'] = '1.1.0';
        (new Filesystem)->put(
            $root.'/module.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        $this->createTableMigration($root, '2026_07_21_000002_create_version_second.php', $secondTable);
        $modules->refresh();

        $pending = $modules->inspect($id);
        $this->assertSame('update_pending', $pending['status']);
        $this->assertSame('1.0.0', $pending['stored_version']);
        $this->assertSame(1, $pending['pending_count']);

        $approved = $modules->enable($id);

        $this->assertSame('enabled', $approved['status']);
        $this->assertSame('1.1.0', $approved['stored_version']);
        $this->assertSame(1, $approved['migration_result']['applied_count']);
        $this->assertTrue(Schema::hasTable($secondTable));
    }

    public function test_failed_module_migration_rolls_back_current_batch_and_keeps_module_inactive(): void
    {
        $id = 'migration-failure-fixture';
        $root = $this->createModule($id, [
            'name' => 'Migration Failure Fixture',
            'migrations' => 'database/migrations',
        ]);
        $table = 'module_failure_fixture_records';
        $this->createTableMigration($root, '2026_07_21_000001_create_failure_records.php', $table);
        $this->createFailingMigration($root, '2026_07_21_000002_fail_installation.php');

        $modules = app(ModuleManager::class);
        $modules->refresh();

        try {
            $modules->enable($id);
            $this->fail('A failed module migration was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                __('Module database migration failed. Applied changes were rolled back and module code remains inactive.'),
                $exception->getMessage(),
            );
        }

        $modules->refresh();
        $failed = $modules->inspect($id);

        $this->assertSame('migration_error', $failed['status']);
        $this->assertFalse($failed['enabled']);
        $this->assertTrue($failed['can_enable']);
        $this->assertFalse(Schema::hasTable($table));
        $this->assertDatabaseMissing('cms_module_migrations', ['module_id' => $id]);
        $this->assertDatabaseHas('cms_modules', [
            'id' => $id,
            'enabled' => false,
        ]);

        $recoveredTable = 'module_failure_fixture_recovered';
        $this->createTableMigration(
            $root,
            '2026_07_21_000002_fail_installation.php',
            $recoveredTable,
        );
        $modules->refresh();
        $recovered = $modules->enable($id);

        $this->assertSame('enabled', $recovered['status']);
        $this->assertSame(2, $recovered['migration_result']['applied_count']);
        $this->assertTrue(Schema::hasTable($table));
        $this->assertTrue(Schema::hasTable($recoveredTable));
        $this->assertNull(ModuleState::query()->findOrFail($id)->migration_error);
    }

    public function test_applied_module_migration_cannot_be_modified_in_place(): void
    {
        $id = 'migration-checksum-fixture';
        $root = $this->createModule($id, [
            'name' => 'Migration Checksum Fixture',
            'migrations' => 'database/migrations',
        ]);
        $table = 'module_checksum_fixture_records';
        $migration = '2026_07_21_000001_create_checksum_records.php';
        $path = $this->createTableMigration($root, $migration, $table);

        $modules = app(ModuleManager::class);
        $modules->refresh();
        $modules->enable($id);

        (new Filesystem)->append($path, "\n// Changed after installation.\n");
        $modules->refresh();
        $modified = $modules->inspect($id);

        $this->assertSame('migration_modified', $modified['status']);
        $this->assertSame([$migration], $modified['modified_migrations']);
        $this->assertFalse($modified['can_enable']);
        $this->assertSame([], $modules->enabled());
    }

    public function test_applied_module_migration_cannot_be_removed_in_place(): void
    {
        $id = 'migration-missing-fixture';
        $root = $this->createModule($id, [
            'name' => 'Migration Missing Fixture',
            'migrations' => 'database/migrations',
        ]);
        $table = 'module_missing_fixture_records';
        $migration = '2026_07_21_000001_create_missing_records.php';
        $path = $this->createTableMigration($root, $migration, $table);

        $modules = app(ModuleManager::class);
        $modules->refresh();
        $modules->enable($id);

        $files = new Filesystem;
        $manifest = json_decode($files->get($root.'/module.json'), true, flags: JSON_THROW_ON_ERROR);
        unset($manifest['migrations']);
        $files->put(
            $root.'/module.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        $modules->refresh();
        $hidden = $modules->inspect($id);

        $this->assertSame('migration_modified', $hidden['status']);
        $this->assertSame([$migration], $hidden['missing_migrations']);
        $this->assertSame([], $modules->enabled());

        $manifest['migrations'] = 'database/migrations';
        $files->put(
            $root.'/module.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
        $files->delete($path);
        $modules->refresh();
        $missing = $modules->inspect($id);

        $this->assertSame('migration_modified', $missing['status']);
        $this->assertSame([$migration], $missing['missing_migrations']);
        $this->assertFalse($missing['can_enable']);
        $this->assertSame([], $modules->enabled());
        $this->assertTrue(Schema::hasTable($table));
    }

    public function test_failed_update_rolls_back_only_the_new_batch_and_preserves_previous_module_data(): void
    {
        $id = 'migration-batch-fixture';
        $root = $this->createModule($id, [
            'name' => 'Migration Batch Fixture',
            'migrations' => 'database/migrations',
        ]);
        $originalTable = 'module_batch_fixture_original';
        $newTable = 'module_batch_fixture_new';
        $firstMigration = '2026_07_21_000001_create_batch_original.php';
        $this->createTableMigration($root, $firstMigration, $originalTable);

        $modules = app(ModuleManager::class);
        $modules->refresh();
        $modules->enable($id);
        DB::table($originalTable)->insert(['value' => 'keep-me']);

        $this->createTableMigration($root, '2026_07_21_000002_create_batch_new.php', $newTable);
        $this->createFailingMigration($root, '2026_07_21_000003_fail_batch_update.php');
        $modules->refresh();

        try {
            $modules->enable($id);
            $this->fail('A failed module database update was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                __('Module database migration failed. Applied changes were rolled back and module code remains inactive.'),
                $exception->getMessage(),
            );
        }

        $modules->refresh();
        $failed = $modules->inspect($id);

        $this->assertSame('migration_error', $failed['status']);
        $this->assertTrue($failed['enabled']);
        $this->assertSame([], $modules->enabled());
        $this->assertTrue(Schema::hasTable($originalTable));
        $this->assertSame('keep-me', DB::table($originalTable)->value('value'));
        $this->assertFalse(Schema::hasTable($newTable));
        $this->assertDatabaseHas('cms_module_migrations', [
            'module_id' => $id,
            'migration' => $firstMigration,
            'batch' => 1,
        ]);
        $this->assertSame(1, DB::table('cms_module_migrations')->where('module_id', $id)->count());
    }

    public function test_module_migration_lock_rejects_parallel_database_operations(): void
    {
        $id = 'migration-lock-fixture';
        $root = $this->createModule($id, [
            'name' => 'Migration Lock Fixture',
            'migrations' => 'database/migrations',
        ]);
        $this->createTableMigration(
            $root,
            '2026_07_21_000001_create_lock_records.php',
            'module_lock_fixture_records',
        );

        $lock = Cache::lock('kaevcms:module-migrations:'.$id, 300);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage(__('Another database operation is already running for this module. Try again shortly.'));
            app(ModuleManager::class)->enable($id);
        } finally {
            $lock->release();
        }
    }

    public function test_owner_module_installation_and_failed_migration_are_audited(): void
    {
        $owner = $this->createAdmin(AdminRole::Owner, 'migration-owner@example.test');
        $successId = 'migration-audit-success';
        $successRoot = $this->createModule($successId, [
            'name' => 'Migration Audit Success',
            'migrations' => 'database/migrations',
        ]);
        $this->createTableMigration(
            $successRoot,
            '2026_07_21_000001_create_audit_success.php',
            'module_audit_success_records',
        );

        $this->actingAs($owner, 'admin')
            ->post('/admin/modules/'.$successId.'/enable')
            ->assertRedirect(route('admin.modules.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('audit_logs', [
            'category' => 'module',
            'action' => 'module.installed',
            'target_name' => $successId,
        ]);

        $failureId = 'migration-audit-failure';
        $failureRoot = $this->createModule($failureId, [
            'name' => 'Migration Audit Failure',
            'migrations' => 'database/migrations',
        ]);
        $this->createFailingMigration($failureRoot, '2026_07_21_000001_fail_audit.php');

        $this->actingAs($owner, 'admin')
            ->post('/admin/modules/'.$failureId.'/enable')
            ->assertRedirect(route('admin.modules.index'))
            ->assertSessionHasErrors('module');

        $this->assertDatabaseHas('audit_logs', [
            'category' => 'module',
            'action' => 'module.migration_failed',
            'target_name' => $failureId,
        ]);
    }

    public function test_invalid_migration_filename_is_rejected_by_manifest_validation(): void
    {
        $id = 'migration-name-fixture';
        $root = $this->createModule($id, [
            'name' => 'Migration Name Fixture',
            'migrations' => 'database/migrations',
        ]);
        $files = new Filesystem;
        $files->ensureDirectoryExists($root.'/database/migrations');
        $files->put($root.'/database/migrations/create_table.php', "<?php\n");
        app(ModuleManager::class)->refresh();

        $module = app(ModuleManager::class)->inspect($id);

        $this->assertFalse($module['valid']);
        $this->assertContains(
            __('Invalid or unsafe module migration file: :file.', ['file' => 'create_table.php']),
            $module['errors'],
        );
    }

    /** @param array<string, mixed> $overrides */
    private function createModule(string $id, array $overrides = []): string
    {
        $root = base_path('modules/'.$id);
        $manifest = array_merge([
            'schema' => 1,
            'id' => $id,
            'name' => str($id)->replace('-', ' ')->headline()->toString(),
            'version' => '1.0.0',
            'author' => 'KaevCMS Tests',
            'description' => 'Test module.',
            'cms_min' => '0.24.0',
            'cms_max' => null,
        ], $overrides);

        $files = new Filesystem;
        $files->ensureDirectoryExists($root);
        $files->put(
            $root.'/module.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        $this->createdModules[] = $id;
        app(ModuleManager::class)->refresh();

        return $root;
    }

    private function createTableMigration(string $root, string $name, string $table): string
    {
        $path = $root.'/database/migrations/'.$name;
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, str_replace('__TABLE__', $table, <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('__TABLE__', function (Blueprint $table): void {
            $table->id();
            $table->string('value')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('__TABLE__');
    }
};
PHP));
        $this->createdTables[] = $table;
        app(ModuleManager::class)->refresh();

        return $path;
    }

    private function createFailingMigration(string $root, string $name): string
    {
        $path = $root.'/database/migrations/'.$name;
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        throw new \RuntimeException('Intentional migration fixture failure.');
    }

    public function down(): void {}
};
PHP);
        app(ModuleManager::class)->refresh();

        return $path;
    }

    private function createAdmin(AdminRole $role, string $email): Admin
    {
        return Admin::query()->create([
            'name' => $role->label(),
            'email' => $email,
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
            'role' => $role,
        ]);
    }
}
