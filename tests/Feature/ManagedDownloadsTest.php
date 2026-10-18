<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\CmsSetting;
use App\Models\Page;
use App\Models\User;
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
                    ->assertSee('data-download-file="download_client_url"', false)
                    ->assertSee('data-download-file="download_patch_url"', false)
                    ->assertSee('assets/css/downloads.css', false);
                $this->assertSame(2, substr_count($response->getContent(), 'type="button" disabled'));
            }
        }
    }

    public function test_administrator_edits_links_and_renames_the_page_without_breaking_the_button(): void
    {
        $page = $this->downloads();
        $payload = $this->payload($page);
        // The reserved address stays downloads; its title is freely editable.
        $payload['translations']['ru']['title'] = 'Файлы нашего сервера';
        $payload['download_links'] = ['client' => 'https://files.example.test/client.zip?mirror=1&lang=ru', 'patch' => 'https://files.example.test/patch.zip'];
        $this->actingAs(Admin::factory()->create(), 'admin')->put('/admin/pages/'.$page->id, $payload)
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.pages.index'));
        $page->refresh();
        $this->get('/downloads')->assertRedirect(page_url($page, 'ru'));
        $this->get(page_url($page, 'ru'))->assertOk()->assertSee('Файлы нашего сервера')
            ->assertSee('href="https://files.example.test/client.zip?mirror=1&amp;lang=ru"', false);
        $this->get(page_url($page, 'en'))->assertOk()->assertSee('Download patch')->assertSee('https://files.example.test/patch.zip');
        $this->assertSame($page->id, app(DownloadPages::class)->selectedId());
    }

    public function test_file_link_validation_rejects_active_content_credentials_and_non_http_protocols(): void
    {
        $page = $this->downloads();
        $this->actingAs(Admin::factory()->create(), 'admin');
        foreach (['javascript:alert(1)', 'data:text/html,test', '//evil.test/file', 'ftp://files.test/game.zip', 'https://login:secret@files.test/game.zip', "https://files.test/a\nb"] as $invalid) {
            $payload = $this->payload($page);
            $payload['download_links'] = ['client' => $invalid, 'patch' => ''];
            $this->put('/admin/pages/'.$page->id, $payload)->assertSessionHasErrors('download_links.client');
            $this->assertNull($page->fresh()->download_client_url);
        }
        $page->update(['download_client_url' => 'javascript:alert(1)']);
        $this->get(page_url($page))->assertOk()->assertDontSee('javascript:alert(1)', false);
    }

    public function test_missing_link_fields_preserve_existing_links_and_empty_fields_disable_them(): void
    {
        $page = $this->downloads();
        $page->update(['download_client_url' => 'https://files.test/client.zip']);
        $this->actingAs(Admin::factory()->create(), 'admin');
        $this->put('/admin/pages/'.$page->id, $this->payload($page))->assertSessionHasNoErrors();
        $this->assertSame('https://files.test/client.zip', $page->fresh()->download_client_url);
        $payload = $this->payload($page);
        $payload['download_links'] = ['client' => '', 'patch' => ''];
        $this->put('/admin/pages/'.$page->id, $payload)->assertSessionHasNoErrors();
        $this->assertNull($page->fresh()->download_client_url);
    }

    public function test_page_preview_includes_unsaved_links_without_persisting_content_or_links(): void
    {
        $page = $this->downloads();
        $before = $page->toArray();
        $payload = $this->payload($page);
        $payload['page_id'] = $page->id;
        $payload['download_links'] = ['client' => 'https://files.test/preview.zip', 'patch' => ''];
        $this->actingAs(Admin::factory()->create(), 'admin')->put('/admin/pages/preview', $payload)
            ->assertOk()->assertSee('https://files.test/preview.zip')->assertSee('data-download-file', false);
        $this->assertSame($before, $page->fresh()->load('translations')->toArray());
    }

    public function test_downloads_key_automatically_connects_a_new_page_without_settings_or_template_edits(): void
    {
        $original = $this->downloads();
        $other = $this->otherPage();
        $this->actingAs(Admin::factory()->create(), 'admin');
        $payload = $this->payload($other);
        $payload['download_links'] = ['client' => 'https://files.test/ignored.zip'];
        $this->put('/admin/pages/'.$other->id, $payload)->assertSessionHasNoErrors();
        $this->assertNull($other->fresh()->download_client_url);
        $this->delete('/admin/pages/'.$original->id)->assertRedirect();
        $payload = $this->payload($original);
        $payload['translations']['ru']['title'] = 'Мои файлы';
        $payload['download_links'] = ['client' => 'https://files.test/new.zip'];
        $this->post('/admin/pages', $payload)->assertSessionHasNoErrors()->assertRedirect();
        $new = $this->downloads();
        $this->assertNotSame($original->id, $new->id);
        $this->get('/downloads')->assertRedirect(page_url($new));
        $this->get(page_url($new))->assertSee('Мои файлы')->assertSee('https://files.test/new.zip');
        $this->get(page_url($other))->assertDontSee('data-download-file', false);
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
        $this->get('/admin/pages/create?template=downloads')->assertOk()->assertSee('value="downloads"', false)->assertSee('data-download-links-editor', false);
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

    public function test_content_managers_can_edit_download_links_while_auditors_and_guests_cannot(): void
    {
        $this->get('/admin/pages/create?template=downloads')->assertRedirect(route('admin.login'));
        $page = $this->downloads();
        foreach (['owner', 'administrator', 'editor'] as $role) {
            $payload = $this->payload($page);
            $payload['download_links'] = ['client' => 'https://files.test/'.$role.'.zip'];
            $this->actingAs(Admin::factory()->create(['role' => $role]), 'admin')->put('/admin/pages/'.$page->id, $payload)->assertSessionHasNoErrors()->assertRedirect();
            $this->assertSame('https://files.test/'.$role.'.zip', $page->fresh()->download_client_url);
        }
        $this->actingAs(Admin::factory()->create(['role' => 'auditor']), 'admin')->put('/admin/pages/'.$page->id, $payload)->assertForbidden();
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
