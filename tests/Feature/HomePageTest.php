<?php

namespace Tests\Feature;

use App\Support\Themes\ThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_theme_assets_are_versioned_for_cache_invalidation(): void
    {
        $this->seed();

        foreach (['default', 'kaev-aurelia'] as $theme) {
            $manager = app(ThemeManager::class);
            $manager->activate($theme);
            $version = $manager->inspect($theme)['version'];
            $this->assertNotEmpty($version);
            $this->get('/')->assertOk()
                ->assertSee('/themes/'.$theme.'/assets/css/app.css?v='.$version, false)
                ->assertSee('/themes/'.$theme.'/assets/js/app.js?v='.$version, false);
        }
    }

    public function test_home_page_is_available(): void
    {
        app(ThemeManager::class)->activate('default');
        $this->seed();

        $this->get('/')
            ->assertOk()
            ->assertSee('LINEAGE II');
    }
}
