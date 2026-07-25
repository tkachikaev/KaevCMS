<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_theme_uses_one_footer_section_and_no_static_files_header_item(): void
    {
        $this->seed();

        $content = $this->get('/')->assertOk()->getContent();
        $this->assertIsString($content);

        $this->assertSame(1, preg_match('/<nav id="main-menu".*?<\/nav>/s', $content, $navigation));
        $this->assertStringNotContainsString('>Файлы</a>', $navigation[0]);

        $this->assertSame(1, preg_match('/<footer class="site-footer".*?<\/footer>/s', $content, $footer));
        $this->assertStringContainsString('Разделы', $footer[0]);
        $this->assertStringNotContainsString('Скачать клиент', $footer[0]);
        $this->assertStringNotContainsString('Навигация', $footer[0]);
        $this->assertStringNotContainsString('Документы', $footer[0]);
        $this->assertStringContainsString('class="footer-section-links"', $footer[0]);

        $css = file_get_contents(public_path('themes/default/assets/css/app.css'));
        $this->assertIsString($css);
        $this->assertStringContainsString('grid-auto-flow:column', $css);
        $this->assertStringContainsString('grid-template-rows:repeat(3,auto)', $css);
    }

    public function test_public_theme_assets_are_versioned_for_cache_invalidation(): void
    {
        $this->seed();

        $this->get('/')
            ->assertOk()
            ->assertSee('/themes/default/assets/css/app.css?v=0.8.1', false)
            ->assertSee('/themes/default/assets/js/app.js?v=0.8.1', false);
    }

    public function test_home_page_is_available(): void
    {
        $this->seed();

        $this->get('/')
            ->assertOk()
            ->assertSee('LINEAGE II');
    }
}
