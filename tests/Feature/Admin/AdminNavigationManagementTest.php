<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\NavigationLink;
use App\Services\Localization\LanguageManager;
use App\Support\Database\TableExistence;
use App\Support\Themes\ThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminNavigationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_manage_navigation_and_editor_can_manage_it_but_auditor_cannot_change_it(): void
    {
        $this->get('/admin/navigation')->assertRedirect(route('admin.login'));

        $editor = Admin::factory()->editor()->create();
        $this->actingAs($editor, 'admin')->get('/admin/navigation')->assertOk();
        $this->actingAs($editor, 'admin')->get('/admin/navigation/create')->assertOk();
        $this->actingAs($editor, 'admin')->post('/admin/navigation', $this->payload())->assertRedirect(route('admin.navigation.index'));

        $auditor = Admin::factory()->auditor()->create();
        $this->actingAs($auditor, 'admin')->get('/admin/navigation')->assertOk();
        $this->actingAs($auditor, 'admin')->post('/admin/navigation', $this->payload())->assertForbidden();
        $this->actingAs($auditor, 'admin')->put('/admin/navigation/1', $this->payload())->assertForbidden();
        $this->actingAs($auditor, 'admin')->delete('/admin/navigation/1')->assertForbidden();
    }

    public function test_custom_links_are_localized_visible_in_both_public_theme_headers_and_footers_and_can_be_disabled(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin')
            ->post('/admin/navigation', $this->payload())->assertRedirect(route('admin.navigation.index'));

        $link = NavigationLink::query()->with('translations')->firstOrFail();
        $this->assertDatabaseHas('navigation_links', [
            'id' => $link->id,
            'url' => '/forum/',
            'is_active' => true,
            'show_in_header' => true,
            'show_in_footer' => true,
            'open_new_tab' => true,
        ]);
        $this->assertDatabaseHas('navigation_link_translations', [
            'navigation_link_id' => $link->id,
            'locale' => 'ru',
            'title' => 'Форум',
        ]);

        foreach (['default', 'kaev-aurelia'] as $theme) {
            app(ThemeManager::class)->activate($theme);
            $this->assertNavigationAnchor('/ru', '/forum/', 'Форум', true);
            $this->assertNavigationAnchor('/en', '/forum/', 'Forum', true);
        }

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->put('/admin/navigation/'.$link->id, $this->payload(['is_active' => '0']))
            ->assertRedirect(route('admin.navigation.index'));
        $this->get('/ru')->assertOk()->assertDontSee('href="/forum/"', false);

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->delete('/admin/navigation/'.$link->id)->assertRedirect(route('admin.navigation.index'));
        $this->assertDatabaseMissing('navigation_links', ['id' => $link->id]);
        $this->assertDatabaseMissing('navigation_link_translations', ['navigation_link_id' => $link->id]);
    }

    public function test_admin_navigation_previews_do_not_require_third_party_images(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin')
            ->post('/admin/navigation', $this->payload())->assertRedirect();

        $policy = (string) $this->get('/admin/navigation')
            ->assertOk()
            ->assertDontSee('href="https://www.google.com/s2/favicons', false)
            ->headers->get('Content-Security-Policy');
        // Link previews are inline SVG: the admin policy still loads no third-party images.
        $this->assertStringContainsString("; img-src 'self' data: blob:;", $policy);
        $this->assertStringStartsWith("default-src 'self'; style-src 'self' 'nonce-", $policy);
    }

    public function test_external_link_and_default_language_fallback_are_rendered_without_spa_interception(): void
    {
        $payload = $this->payload(['url' => 'https://discord.gg/example', 'open_new_tab' => '0']);
        $payload['translations']['en']['title'] = '';

        $this->actingAs(Admin::factory()->create(), 'admin')->post('/admin/navigation', $payload)->assertRedirect();
        $this->assertNavigationAnchor('/en', 'https://discord.gg/example', 'Форум', false);
    }

    public function test_unsafe_urls_and_missing_required_fields_are_rejected_without_writing_data(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        foreach ([
            'javascript:alert(1)',
            'data:text/html,evil',
            '//evil.example/phishing',
            '/\\evil.example',
            'https://user:pass@example.com/',
            'ftp://example.com/',
            "https://example.com/\nscript",
        ] as $url) {
            $this->post('/admin/navigation', $this->payload(['url' => $url]))->assertSessionHasErrors('url');
        }

        $this->post('/admin/navigation', $this->payload(['show_in_header' => '0', 'show_in_footer' => '0']))
            ->assertSessionHasErrors('show_in_header');

        $payload = $this->payload();
        $payload['translations'][app(LanguageManager::class)->default()]['title'] = '';
        $this->post('/admin/navigation', $payload)->assertSessionHasErrors();

        $this->assertDatabaseCount('navigation_links', 0);
    }

    public function test_public_theme_can_render_before_new_tables_are_migrated(): void
    {
        Schema::drop('navigation_link_translations');
        Schema::drop('navigation_links');
        app(TableExistence::class)->forget();

        $this->get('/ru')->assertOk();
    }

    private function assertNavigationAnchor(string $page, string $url, string $title, bool $newTab): void
    {
        $response = $this->get($page)->assertOk();
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $matches = 0;
        foreach ($document->getElementsByTagName('a') as $anchor) {
            if ($anchor->getAttribute('href') !== $url) {
                continue;
            }
            $matches++;
            $this->assertSame($title, trim($anchor->textContent));
            $this->assertSame($newTab ? '_blank' : '', $anchor->getAttribute('target'));
            $this->assertSame($newTab ? 'noopener noreferrer' : '', $anchor->getAttribute('rel'));
            $this->assertFalse($anchor->hasAttribute('wire:navigate'));
            $this->assertFalse($anchor->hasAttribute('wire:navigate.hover'));
        }
        $this->assertGreaterThanOrEqual(2, $matches, 'Custom link must appear in both header and footer.');
    }

    /** @param array<string, string> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'translations' => [
                'ru' => ['title' => 'Форум'],
                'en' => ['title' => 'Forum'],
            ],
            'url' => '/forum/',
            'is_active' => '1',
            'show_in_header' => '1',
            'show_in_footer' => '1',
            'open_new_tab' => '1',
            'sort_order' => '10',
        ], $overrides);
    }
}
