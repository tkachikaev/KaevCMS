<?php

namespace Tests\Feature\Performance;

use App\Models\CmsSetting;
use App\Models\Page;
use App\Services\CmsSettings;
use App\Services\Localization\LanguageManager;
use App\Services\Pages\PageKeys;
use App\Support\Database\TableExistence;
use App\Support\Modules\ModuleAutoloader;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleMigrationManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guards the per-request database work found by the load test (tests/load/RESULTS.md):
 * repeated table-exists checks and one query per setting or per module.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_existing_table_is_checked_once_and_a_missing_one_again(): void
    {
        $tables = new TableExistence;
        $queries = $this->countQueries(function () use ($tables): void {
            $this->assertTrue($tables->has('cms_settings'));
            $this->assertTrue($tables->has('cms_settings'));
            $this->assertTrue($tables->has('cms_settings'));
        });
        $this->assertSame(1, $queries);

        $this->assertFalse($tables->has('load_budget_probe'));
        Schema::create('load_budget_probe', static function ($table): void {
            $table->id();
        });
        $this->assertTrue($tables->has('load_budget_probe'), 'A table created later must be seen without a restart.');

        Schema::drop('load_budget_probe');
        $tables->forget();
        $this->assertFalse($tables->has('load_budget_probe'));
    }

    public function test_finished_migrations_flush_remembered_tables(): void
    {
        $tables = app(TableExistence::class);
        $this->assertTrue($tables->has('cms_settings'));

        Event::dispatch(new MigrationsEnded('up'));

        $queries = $this->countQueries(fn () => $tables->has('cms_settings'));
        $this->assertSame(1, $queries);
    }

    public function test_settings_are_read_with_one_query_and_writes_are_visible(): void
    {
        CmsSetting::query()->create(['key' => 'budget.first', 'value' => 'one']);
        CmsSetting::query()->create(['key' => 'budget.second', 'value' => 'two']);
        $settings = app(CmsSettings::class);
        $settings->forget();

        $queries = $this->countQueries(function () use ($settings): void {
            $this->assertSame('one', $settings->get('budget.first'));
            $this->assertSame('two', $settings->get('budget.second'));
            $this->assertSame('fallback', $settings->get('budget.missing', 'fallback'));
            $this->assertSame(['budget.first' => 'one', 'budget.third' => 'x'], $settings->getMany(['budget.first' => null, 'budget.third' => 'x']));
        });
        $this->assertLessThanOrEqual(2, $queries, 'Reading many settings must not cost a query per setting.');

        CmsSetting::query()->where('key', 'budget.first')->firstOrFail()->update(['value' => 'changed']);
        $this->assertSame('changed', $settings->get('budget.first'), 'A model write must be visible immediately.');

        $settings->set('budget.third', 'saved');
        $this->assertSame('saved', $settings->get('budget.third'));
    }

    public function test_module_migrations_are_read_once_for_all_modules(): void
    {
        $modules = app(ModuleManager::class)->installed();
        $this->assertNotEmpty($modules);
        $manager = app(ModuleMigrationManager::class);
        $manager->forgetApplied();

        $sql = [];
        DB::listen(static function ($query) use (&$sql): void {
            $sql[] = $query->sql;
        });
        foreach ($modules as $module) {
            $manager->inspect($module);
        }

        $reads = array_filter($sql, static fn (string $query): bool => str_contains($query, 'cms_module_migrations') && str_starts_with(strtolower($query), 'select *'));
        $this->assertCount(1, $reads, 'Inspecting every module must read applied migrations once.');
    }

    public function test_the_downloads_page_is_read_once_and_writes_are_visible(): void
    {
        $pages = app(PageKeys::class);
        download_page_url('ru'); // Warm settings and languages that any page reads anyway.
        $pages->forget();

        $queries = $this->countQueries(function (): void {
            download_page_url('ru');
            download_page_url('en');
        });
        $this->assertSame(2, $queries, 'The header and the footer must share one read of the page and its translations.');

        $page = $pages->find('downloads');
        $this->assertInstanceOf(Page::class, $page);
        $page->translations()->where('locale', 'en')->firstOrFail()->update(['slug' => 'get-the-game']);
        $this->assertSame('get-the-game', $pages->find('downloads')?->translation('en')?->slug, 'A translation write must be visible immediately.');

        $page->delete();
        $this->assertNull($pages->find('downloads'), 'A deleted page must not be remembered.');
    }

    public function test_booting_healthy_modules_neither_writes_nor_rescans_them(): void
    {
        $manager = app(ModuleManager::class);
        $manager->enable('daily-rewards');
        $manager->refresh();

        $sql = [];
        DB::listen(static function ($query) use (&$sql): void {
            $sql[] = strtolower($query->sql);
        });
        (new ModuleRuntime(app(), app(ModuleAutoloader::class)))->bootEnabled($manager);
        $writes = array_filter($sql, static fn (string $query): bool => ! str_starts_with($query, 'select'));
        $this->assertSame([], array_values($writes), 'A module without a stored error must not be written on every request.');

        $queries = $this->countQueries(fn () => $manager->enabled());
        $this->assertSame(0, $queries, 'Booting healthy modules must keep the scanned module list.');
    }

    public function test_translation_coverage_is_computed_only_for_the_languages_page(): void
    {
        $languages = app(LanguageManager::class);

        $this->assertArrayNotHasKey('coverage', $languages->installed()['ru'], 'Every request reads installed languages; coverage parses all JSON files.');
        $this->assertSame(100, $languages->installedWithCoverage()['en']['coverage']);
        $this->assertGreaterThan(90, $languages->installedWithCoverage()['ru']['coverage']);
    }

    public function test_public_pages_stay_within_their_query_budget(): void
    {
        foreach (['/' => 16, '/news' => 8, '/ru/pages/about' => 8, '/login' => 8] as $path => $budget) {
            $this->get($path); // Warm per-request caches the way a first visitor would.
            $queries = $this->countQueries(fn () => $this->get($path)->assertOk());
            $this->assertLessThanOrEqual($budget, $queries, "{$path} used {$queries} queries; the budget is {$budget}.");
        }
    }

    private function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(static function () use (&$count): void {
            $count++;
        });
        $callback();

        return $count;
    }
}
