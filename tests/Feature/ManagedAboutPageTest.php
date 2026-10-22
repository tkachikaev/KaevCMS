<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\CmsSetting;
use App\Models\GameServer;
use App\Models\Page;
use App\Services\Pages\AboutPages;
use App\Support\Themes\ThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagedAboutPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_installation_provides_an_editable_about_text_above_the_server_cards_in_both_themes(): void
    {
        GameServer::factory()->create(['name' => 'Aden x1', 'chronicle' => 'Interlude']);
        $page = $this->about();
        $this->assertTrue($page->isLive());
        $this->assertFalse($page->show_in_header);
        $this->assertFalse($page->show_in_footer);

        foreach (['default', 'kaev-aurelia'] as $theme) {
            app(ThemeManager::class)->activate($theme);
            foreach (['ru' => ['О сервере', 'Мы создали этот мир', 'Хроники'], 'en' => ['About the server', 'We built this world', 'Chronicle']] as $locale => [$title, $text, $chronicle]) {
                $this->get('/'.$locale.'/about')->assertOk()
                    ->assertSeeInOrder([$title, $text, 'Aden x1', $chronicle, 'Interlude'])
                    ->assertDontSee('editable in the CMS later')
                    ->assertDontSee('редактировать в CMS позже');
            }
        }
    }

    public function test_edited_text_is_shown_at_about_and_page_links_point_there(): void
    {
        $page = $this->about();
        $payload = $this->payload($page);
        $payload['translations']['ru']['title'] = 'Мир Адена';
        $payload['translations']['ru']['body'] = '<h2>История</h2><p>Наш сервер открылся в 2026 году.</p>';
        $this->actingAs(Admin::factory()->create(), 'admin')
            ->put('/admin/pages/'.$page->id, $payload)
            ->assertSessionHasNoErrors();

        $this->get('/ru/about')->assertOk()->assertSee('Мир Адена')->assertSee('Наш сервер открылся в 2026 году.');
        $this->assertSame(route('localized.about', ['locale' => 'ru']), page_url($page->fresh(), 'ru'));
        $this->get('/ru/pages/about')->assertRedirect(route('localized.about', ['locale' => 'ru']));
        $this->get('/admin/pages')->assertOk()
            ->assertSee('Страница «О сервере»')
            ->assertSee('<p>/about</p>', false)
            ->assertDontSee('data-testid="about-page-notice"', false);
    }

    public function test_without_a_published_page_about_shows_the_server_cards_and_can_be_recreated(): void
    {
        GameServer::factory()->create(['name' => 'Aden x1']);
        $page = $this->about();
        $page->update(['is_published' => false]);

        $this->get('/ru/about')->assertOk()->assertSee('Aden x1')->assertDontSee('Мы создали этот мир');

        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin')->get('/admin/pages')->assertOk()
            ->assertSee('data-testid="about-page-notice"', false)
            ->assertSee(route('admin.pages.edit', $page), false);

        $this->delete('/admin/pages/'.$page->id)->assertRedirect(route('admin.pages.index'));
        $this->assertNull(app(AboutPages::class)->selected());
        $this->get('/ru/about')->assertOk()->assertSee('Aden x1');
        $this->get('/admin/pages')->assertOk()->assertSee(route('admin.pages.create', ['template' => 'about']), false);

        // An update never brings a deleted page back.
        app(AboutPages::class)->initialize();
        $this->assertNull(app(AboutPages::class)->selected());

        // The template starts a new page with the fixed address and starter text.
        $this->get('/admin/pages/create?template=about')->assertOk()
            ->assertSee('value="about"', false)
            ->assertSee('Мы создали этот мир');
        $payload = $this->payload(new Page(['is_published' => true]), app(AboutPages::class)->templateTranslations());
        $this->post('/admin/pages', $payload)->assertSessionHasNoErrors();
        $this->assertNotNull(app(AboutPages::class)->selected());
        $this->get('/ru/about')->assertOk()->assertSee('Мы создали этот мир');
        $this->get('/admin/pages/create?template=about')->assertRedirect(route('admin.pages.edit', app(AboutPages::class)->selected()));
    }

    public function test_an_existing_about_page_is_kept_and_installation_runs_once(): void
    {
        $this->assertTrue(CmsSetting::query()->where('key', AboutPages::KEY)->exists());
        $this->assertSame(1, Page::query()->where('slug', AboutPages::SLUG)->count());
        app(AboutPages::class)->initialize();
        $this->assertSame(1, Page::query()->where('slug', AboutPages::SLUG)->count());
    }

    private function about(): Page
    {
        return app(AboutPages::class)->selected() ?? throw new \RuntimeException('Default about page missing');
    }

    /**
     * @param  array<string, array<string, string>>|null  $translations
     * @return array<string, mixed>
     */
    private function payload(Page $page, ?array $translations = null): array
    {
        $translations ??= $page->translations->keyBy('locale')->map(fn ($translation) => $translation->only(['title', 'slug', 'body', 'seo_title', 'seo_description']))->all();

        return ['translations' => array_map(static fn (array $values): array => array_intersect_key($values, array_flip(['title', 'slug', 'body', 'seo_title', 'seo_description'])), $translations),
            'is_published' => $page->is_published, 'show_in_header' => false, 'show_in_footer' => false, 'sort_order' => 90];
    }
}
