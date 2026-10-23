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

/**
 * "About the server" and "Download the game" are ordinary pages: theme buttons open the page
 * whose address is "about" or "downloads", and any other key works the same way.
 */
class KeyedPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_installation_creates_two_example_pages_and_theme_buttons_open_them(): void
    {
        GameServer::factory()->create(['name' => 'Aden x1', 'chronicle' => 'Interlude']);
        $about = $this->page('about');
        $downloads = $this->page('downloads');
        $this->assertTrue($about->isLive());
        $this->assertTrue($downloads->isLive());

        foreach (['default', 'kaev-aurelia'] as $theme) {
            app(ThemeManager::class)->activate($theme);
            $this->get('/ru')->assertOk()
                ->assertSee('href="'.page_url($about, 'ru').'"', false)
                ->assertSee('href="'.page_url($downloads, 'ru').'"', false);
            // The About page is just its text: no server cards are added to it.
            $this->get(page_url($about, 'ru'))->assertOk()
                ->assertSee('О сервере')->assertSee('Мы создали этот мир')
                ->assertDontSee('about-server-list', false);
        }
    }

    public function test_old_addresses_open_the_pages_by_key(): void
    {
        $about = $this->page('about');
        $this->get('/about')->assertRedirect(page_url($about, 'ru'));
        $this->get('/en/about')->assertRedirect(page_url($about, 'en'));
        $this->get('/ru/pages/about')->assertOk()->assertSee('Мы создали этот мир');
        $this->get('/downloads')->assertRedirect(page_url($this->page('downloads'), 'ru'));
    }

    public function test_pages_can_be_deleted_without_warnings_and_a_new_page_with_the_key_is_picked_up(): void
    {
        $admin = Admin::factory()->create();
        $about = $this->page('about');
        $this->actingAs($admin, 'admin')->get('/admin/pages')->assertOk()
            ->assertSee('/pages/about')
            ->assertDontSee('data-testid="about-page-notice"', false)
            ->assertDontSee('Проверьте страницу скачивания');

        $this->delete('/admin/pages/'.$about->id)->assertRedirect(route('admin.pages.index'));
        $this->delete('/admin/pages/'.$this->page('downloads')->id)->assertRedirect(route('admin.pages.index'));
        $this->get('/admin/pages')->assertOk()
            ->assertDontSee('Создать страницу «О сервере»')
            ->assertDontSee('Создать страницу скачивания');

        // Buttons stay; without a page they lead to "not found".
        $this->get('/ru')->assertOk()->assertSee('href="'.route('pages.show', ['page' => 'about']).'"', false);
        $this->get('/about')->assertRedirect(route('pages.show', ['page' => 'about']));
        $this->get('/pages/about')->assertNotFound();

        // An update never brings a deleted example page back.
        app(AboutPages::class)->initialize();
        $this->assertNull(Page::query()->where('slug', 'about')->first());

        $this->post('/admin/pages', $this->payload('about', 'Наш мир', '<p>Свой текст о сервере.</p>'))->assertSessionHasNoErrors();
        $about = $this->page('about');
        $this->get('/about')->assertRedirect(page_url($about, 'ru'));
        $this->get(page_url($about, 'ru'))->assertOk()->assertSee('Свой текст о сервере.');
    }

    public function test_any_key_works_the_same_way(): void
    {
        $this->assertSame(route('pages.show', ['page' => 'server-rules']), page_key_url('server-rules'));
        $this->actingAs(Admin::factory()->create(), 'admin')
            ->post('/admin/pages', $this->payload('server-rules', 'Правила', '<p>Не ругаться.</p>'))
            ->assertSessionHasNoErrors();
        $page = $this->page('server-rules');
        $this->assertSame(page_url($page), page_key_url('server-rules'));
        $page->update(['is_published' => false]);
        $this->assertSame(route('pages.show', ['page' => 'server-rules']), page_key_url('server-rules'));
    }

    public function test_a_language_without_its_own_address_uses_the_key_given_in_another_language(): void
    {
        $payload = $this->payload('server-files', 'Файлы', '<p>Клиент и патч.</p>');
        $payload['translations']['en'] = ['title' => 'Game files', 'slug' => '', 'body' => '<p>Client and patch.</p>', 'seo_title' => '', 'seo_description' => ''];
        $this->actingAs(Admin::factory()->create(), 'admin')->post('/admin/pages', $payload)->assertSessionHasNoErrors();
        $page = $this->page('server-files');
        $this->assertSame('server-files', $page->translation('en')?->slug);

        // The key may be typed in the second language only; the default language then takes it too.
        $payload = $this->payload('', 'Правила сервера', '<p>Текст правил.</p>');
        $payload['translations']['en'] = ['title' => 'Rules', 'slug' => 'rules', 'body' => '<p>Rules text.</p>', 'seo_title' => '', 'seo_description' => ''];
        $this->post('/admin/pages', $payload)->assertSessionHasNoErrors();
        $this->assertSame('rules', $this->page('rules')->translation('ru')?->slug);
    }

    public function test_example_pages_are_created_once(): void
    {
        $this->assertTrue(CmsSetting::query()->where('key', AboutPages::KEY)->exists());
        $this->assertSame(1, Page::query()->where('slug', 'about')->count());
        app(AboutPages::class)->initialize();
        $this->assertSame(1, Page::query()->where('slug', 'about')->count());
    }

    private function page(string $key): Page
    {
        return Page::query()->with('translations')->where('slug', $key)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(string $slug, string $title, string $body): array
    {
        return [
            'translations' => ['ru' => ['title' => $title, 'slug' => $slug, 'body' => $body, 'seo_title' => '', 'seo_description' => '']],
            'is_published' => true, 'show_in_header' => false, 'show_in_footer' => false, 'sort_order' => 100,
        ];
    }
}
