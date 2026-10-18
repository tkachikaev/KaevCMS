<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\CmsSetting;
use App\Models\Page;
use App\Models\User;
use App\Services\Html\SafeHtmlSanitizer;
use App\Services\Pages\DownloadPages;
use App\Support\Themes\ThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManagedDownloadsTest extends TestCase
{
    use RefreshDatabase;

    public function test_installation_provides_a_bilingual_editable_page_in_both_themes_with_inactive_file_buttons(): void
    {
        $page = $this->downloads();
        $this->assertTrue($page->isLive());
        $this->assertTrue($page->hasTranslation('ru'));
        $this->assertTrue($page->hasTranslation('en'));
        foreach (['default', 'kaev-aurelia'] as $theme) {
            app(ThemeManager::class)->activate($theme);
            foreach (['ru' => 'Скачать игру', 'en' => 'Download the game'] as $locale => $title) {
                $this->get('/'.$locale.'/downloads')->assertRedirect(page_url($page, $locale));
                $response = $this->get(page_url($page, $locale))->assertOk()->assertSee($title)
                    ->assertSee('data-content-block="cards"', false)
                    ->assertSee('data-content-block="card"', false)
                    ->assertSee('assets/css/downloads.css', false);
                $this->assertSame(2, substr_count($response->getContent(), 'aria-disabled="true"'));
            }
        }
    }

    public function test_administrator_edits_every_card_and_button_as_page_content(): void
    {
        $page = $this->downloads();
        $payload = $this->payload($page);
        $payload['translations']['ru']['title'] = 'Файлы нашего сервера';
        $payload['translations']['ru']['body'] = str_replace(
            ['Клиент игры', 'Установите клиент игры перед установкой патча сервера.', 'Скачать клиент', 'href="#"'],
            ['Наш клиент Interlude', 'Моё описание установки.', 'Загрузить архив', 'href="https://files.example.test/client.zip?mirror=1&amp;lang=ru"'],
            $payload['translations']['ru']['body']);
        $this->actingAs(Admin::factory()->create(), 'admin')->put('/admin/pages/'.$page->id, $payload)
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.pages.index'));
        $page->refresh();
        $this->get('/downloads')->assertRedirect(page_url($page, 'ru'));
        $response = $this->get(page_url($page, 'ru'))->assertOk()->assertSee('Файлы нашего сервера')
            ->assertSee('Наш клиент Interlude')->assertSee('Моё описание установки.')->assertSee('Загрузить архив')
            ->assertDontSee('Установите клиент игры перед установкой патча сервера.')
            ->assertSee('href="https://files.example.test/client.zip?mirror=1&amp;lang=ru"', false);
        $this->assertSame(1, substr_count($response->getContent(), '>Наш клиент Interlude</h2>'));
        $this->get(page_url($page, 'en'))->assertOk()->assertSee('Game client')->assertDontSee('Наш клиент Interlude');
    }

    public function test_content_buttons_cannot_bypass_html_sanitization(): void
    {
        $page = $this->downloads();
        $this->actingAs(Admin::factory()->create(), 'admin');
        foreach (['javascript:alert(1)', 'data:text/html,test', '//evil.test/file', 'ftp://files.test/game.zip', 'https://login:secret@files.test/game.zip'] as $invalid) {
            $payload = $this->payload($page);
            $payload['translations']['ru']['body'] = '<div data-content-block="cards" onclick="alert(1)"><div data-content-block="card" style="color:red"><h2>Файлы</h2><p><a data-content-button="primary" href="'.$invalid.'" onclick="alert(1)">Скачать</a></p><form><button>Unsafe form</button></form></div></div>';
            $this->put('/admin/pages/'.$page->id, $payload)->assertSessionHasNoErrors();
            $response = $this->get(page_url($page))->assertOk()->assertSee('data-content-block="card"', false)
                ->assertSee('aria-disabled="true"', false)->assertDontSee('Unsafe form')->assertDontSee('onclick="', false)
                ->assertDontSee('href="'.$invalid.'"', false);
            $this->assertStringNotContainsString('style=', $page->fresh()->bodyFor());
        }
        $sanitizer = app(SafeHtmlSanitizer::class);
        $safe = $sanitizer->sanitize('<div data-content-block="cards"><div data-content-block="card"><p><a data-content-button="primary" href="#">Soon</a></p></div></div>', $sanitizer::PROFILE_PAGE);
        $this->assertSame($safe, $sanitizer->sanitize($safe, $sanitizer::PROFILE_PAGE));
        $this->assertStringNotContainsString('data-content-block', $sanitizer->sanitize($safe, $sanitizer::PROFILE_NEWS));
        $this->assertStringNotContainsString('data-content-button', $sanitizer->sanitize($safe, $sanitizer::PROFILE_NEWS));
    }

    public function test_removing_cards_in_the_editor_removes_them_from_the_site_without_hidden_template_content(): void
    {
        $page = $this->downloads();
        $page->update(['download_client_url' => 'https://files.test/legacy.zip']);
        $payload = $this->payload($page);
        $payload['translations']['ru']['body'] = '<h2>Только мой текст</h2><p>Своя страница без карточек.</p>';
        $this->actingAs(Admin::factory()->create(), 'admin')->put('/admin/pages/'.$page->id, $payload)->assertSessionHasNoErrors();
        foreach (['default', 'kaev-aurelia'] as $theme) {
            app(ThemeManager::class)->activate($theme);
            $this->get(page_url($page))->assertSee('Своя страница без карточек.')->assertDontSee('Клиент игры')
                ->assertDontSee('Патч сервера')->assertDontSee('data-content-block="cards"', false)->assertDontSee('legacy.zip');
        }
    }

    public function test_page_preview_includes_unsaved_card_content_without_persisting_it(): void
    {
        $page = $this->downloads();
        $before = $page->toArray();
        $payload = $this->payload($page);
        $payload['page_id'] = $page->id;
        $payload['translations']['ru']['body'] = '<div data-content-block="cards"><div data-content-block="card"><h2>Мой новый патч</h2><p><a data-content-button="secondary" href="https://files.test/preview.zip">Забрать патч</a></p></div></div>';
        $this->actingAs(Admin::factory()->create(), 'admin')->put('/admin/pages/preview', $payload)
            ->assertOk()->assertSee('https://files.test/preview.zip')->assertSee('Мой новый патч')->assertSee('Забрать патч')
            ->assertDontSee('Клиент игры')->assertDontSee('Патч сервера');
        $this->assertSame($before, $page->fresh()->load('translations')->toArray());
    }

    public function test_downloads_key_automatically_connects_any_editor_content_without_template_edits(): void
    {
        $original = $this->downloads();
        $this->actingAs(Admin::factory()->create(), 'admin')->delete('/admin/pages/'.$original->id)->assertRedirect();
        $payload = $this->payload($original);
        $payload['translations']['ru']['title'] = 'Мои файлы';
        $payload['translations']['ru']['body'] = '<h2>Новый клиент</h2><p><a href="https://files.test/new.zip" data-content-button="primary">Забрать игру</a></p>';
        $this->post('/admin/pages', $payload)->assertSessionHasNoErrors()->assertRedirect();
        $new = $this->downloads();
        $this->assertNotSame($original->id, $new->id);
        $this->get('/downloads')->assertRedirect(page_url($new));
        $this->get(page_url($new))->assertSee('Мои файлы')->assertSee('https://files.test/new.zip')->assertDontSee('Патч сервера');
    }

    public function test_draft_and_deleted_pages_are_private_and_the_admin_can_create_a_prefilled_replacement(): void
    {
        $page = $this->downloads();
        $page->translations()->where('locale', 'ru')->update(['body' => '<p>Private draft marker</p>']);
        $page->update(['is_published' => false]);
        $this->get('/ru/downloads')->assertOk()->assertSee('Файлы пока недоступны')->assertDontSee('Private draft marker');
        $this->actingAs(Admin::factory()->create(), 'admin')->get('/admin/pages')->assertSee('Проверьте страницу скачивания');
        $this->get('/admin/pages/create?template=downloads')->assertRedirect(route('admin.pages.edit', $page));
        $this->assertFalse($page->fresh()->isLive());
        $this->delete('/admin/pages/'.$page->id)->assertRedirect();
        app(DownloadPages::class)->initialize();
        $this->assertNull(app(DownloadPages::class)->selected());
        $this->get('/en/downloads')->assertOk()->assertSee('Files are not available yet')->assertDontSee('Private draft marker');
        $this->get('/admin/pages')->assertSee('Создать страницу скачивания');
        $this->get('/admin/pages/create?template=downloads')->assertOk()->assertSee('value="downloads"', false)->assertSee('data-page-blocks="1"', false);
        $this->assertNull(app(DownloadPages::class)->selected());
        $this->post('/admin/pages', $this->payload($page))->assertSessionHasNoErrors();
        $this->assertNotNull(app(DownloadPages::class)->selected());
    }

    public function test_rerunning_migration_preserves_custom_pages_users_and_links_and_never_recreates_a_deleted_page(): void
    {
        $page = $this->downloads();
        $user = User::factory()->create();
        $other = $this->otherPage();
        $page->update(['download_client_url' => 'https://files.test/keep.zip', 'is_published' => false]);
        $before = Page::with('translations')->get()->toArray();
        $migration = require database_path('migrations/2026_10_18_000000_add_managed_download_page.php');
        $migration->up();
        $this->assertSame($before, Page::with('translations')->get()->toArray());
        $this->assertNotNull($user->fresh());
        $page->forceDelete();
        $remainingPages = Page::with('translations')->get()->toArray();
        $migration->up();
        $this->assertNull(app(DownloadPages::class)->selectedId());
        $this->assertSame($remainingPages, Page::with('translations')->get()->toArray());
    }

    public function test_upgrade_adopts_existing_downloads_content_without_overwriting_it(): void
    {
        $page = $this->downloads();
        $page->update(['download_client_url' => 'https://files.test/custom.zip']);
        $before = $page->load('translations')->toArray();
        CmsSetting::query()->where('key', DownloadPages::KEY)->delete();
        app(DownloadPages::class)->initialize();
        $this->assertSame($before, $page->fresh()->load('translations')->toArray());
        $this->assertSame($page->id, $this->downloads()->id);
        // Legacy content can already own the localized address under another base key.
        $page->update(['slug' => 'custom-key']);
        $before = $page->fresh()->load('translations')->toArray();
        CmsSetting::query()->where('key', DownloadPages::KEY)->delete();
        app(DownloadPages::class)->initialize();
        $this->assertSame($before, $page->fresh()->load('translations')->toArray());
        $this->assertSame(1, Page::count());
    }

    public function test_content_managers_can_edit_the_whole_page_while_auditors_and_guests_cannot(): void
    {
        $this->get('/admin/pages/create?template=downloads')->assertRedirect(route('admin.login'));
        $page = $this->downloads();
        foreach (['owner', 'administrator', 'editor'] as $role) {
            $payload = $this->payload($page);
            $payload['translations']['ru']['body'] = '<p><a data-content-button="primary" href="https://files.test/'.$role.'.zip">'.$role.'</a></p>';
            $this->actingAs(Admin::factory()->create(['role' => $role]), 'admin')->put('/admin/pages/'.$page->id, $payload)->assertSessionHasNoErrors()->assertRedirect();
            $this->assertStringContainsString('https://files.test/'.$role.'.zip', $page->fresh()->bodyFor());
        }
        $this->actingAs(Admin::factory()->create(['role' => 'auditor']), 'admin')->put('/admin/pages/'.$page->id, $payload)->assertForbidden();
    }

    public function test_upgrade_moves_legacy_cards_links_and_all_translations_into_content_exactly_once(): void
    {
        $page = $this->downloads();
        $page->update(['download_client_url' => 'https://files.test/keep.zip?x=1&y=2', 'download_patch_url' => 'https://files.test/patch.zip', 'is_published' => false]);
        $bodies = ['ru' => '<h2>Моя инструкция</h2><p>Не изменять этот текст.</p>', 'en' => '<p>Keep my English instructions.</p>'];
        foreach ($bodies as $locale => $body) {
            $page->translations()->where('locale', $locale)->update(['body' => $body]);
        }
        $other = $this->otherPage()->fresh()->load('translations')->toArray();
        $user = User::factory()->create();
        CmsSetting::where('key', DownloadPages::EDITABLE_KEY)->delete();
        $migration = require database_path('migrations/2026_10_18_010000_move_download_cards_into_page_content.php');
        $migration->up();
        $page->refresh()->load('translations');
        foreach ($bodies as $locale => $body) {
            $html = $page->bodyFor($locale);
            $this->assertStringEndsWith($body, $html);
            $this->assertStringContainsString('https://files.test/keep.zip?x=1&amp;y=2', $html);
            $this->assertStringContainsString('https://files.test/patch.zip', $html);
            $this->assertSame(2, substr_count($html, 'data-content-block="card"'));
        }
        $before = $page->toArray();
        $migration->up();
        $migration->down();
        $this->assertSame($before, $page->fresh()->load('translations')->toArray());
        $this->assertFalse($page->isLive());
        $this->assertSame($other, Page::find($other['id'])->load('translations')->toArray());
        $this->assertNotNull($user->fresh());
        $page->forceDelete();
        $migration->up();
        $this->assertNull(app(DownloadPages::class)->selected());
    }

    public function test_visual_cards_are_available_on_ordinary_pages_and_never_forced_on_a_recreated_download_page(): void
    {
        $page = $this->otherPage();
        $payload = $this->payload($page);
        $payload['translations']['ru']['body'] = $this->downloads()->bodyFor('ru');
        $this->actingAs(Admin::factory()->create(), 'admin')->put('/admin/pages/'.$page->id, $payload)->assertSessionHasNoErrors();
        $this->get(page_url($page))->assertOk()->assertSee('data-content-block="cards"', false)->assertSee('Клиент игры');
        $this->get('/admin/pages/'.$page->id.'/edit')->assertSee('data-page-blocks="1"', false)->assertSee('Карточки');
    }

    private function downloads(): Page
    {
        return app(DownloadPages::class)->selected() ?? throw new \RuntimeException('Default download page missing');
    }

    private function otherPage(): Page
    {
        $page = Page::create(['slug' => 'custom-files', 'is_published' => true]);
        $page->translations()->create(['locale' => 'ru', 'slug' => 'custom-files', 'title' => 'Другие файлы', 'body' => '<p>Описание файлов.</p>']);

        return $page->load('translations');
    }

    /** @return array<string, mixed> */
    private function payload(Page $page): array
    {
        return ['translations' => $page->translations->keyBy('locale')->map(fn ($translation) => $translation->only(['title', 'slug', 'body', 'seo_title', 'seo_description']))->all(),
            'is_published' => $page->is_published, 'show_in_header' => false, 'show_in_footer' => false, 'sort_order' => 100];
    }
}
