<?php

namespace Tests\Feature;

use App\Models\GameServer;
use App\Models\News;
use App\Models\Page;
use App\Services\CmsSettings;
use App\Services\Localization\LanguageManager;
use App\Services\Localization\LocalizedContentResolver;
use App\Services\MailTemplateSettings;
use App\Services\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DynamicLanguageFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_russian_content_is_not_used_as_runtime_fallback(): void
    {
        $metadataDirectory = lang_path('de');
        $metadataPath = $metadataDirectory.DIRECTORY_SEPARATOR.'language.php';
        $jsonPath = lang_path('de.json');

        File::ensureDirectoryExists($metadataDirectory);
        File::put($metadataPath, <<<'PHP'
<?php

return [
    'code' => 'de',
    'name' => 'German',
    'native_name' => 'Deutsch',
    'direction' => 'ltr',
    'fallback' => 'en',
    'author' => 'Test pack',
];
PHP);
        File::put($jsonPath, json_encode(['Home' => 'Startseite'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        try {
            $this->forgetLocalizedServices();
            $languages = $this->app->make(LanguageManager::class);
            $languages->update(['en', 'de'], 'de', 'en');
            $this->forgetLocalizedServices(false);

            $page = Page::query()->create([
                'slug' => 'legacy-page',
                'is_published' => true,
                'show_in_header' => false,
                'show_in_footer' => false,
                'sort_order' => 100,
            ]);
            $page->translations()->createMany([
                [
                    'locale' => 'ru',
                    'title' => 'Русская страница',
                    'slug' => 'russian-page',
                    'body' => '<p>Русский текст</p>',
                ],
                [
                    'locale' => 'en',
                    'title' => 'English page',
                    'slug' => 'english-page',
                    'body' => '<p>English text</p>',
                ],
            ]);

            $this->assertSame('en', $page->fresh()->translation('de')?->locale);
            $this->get('/de/pages/russian-page')->assertNotFound();
            $this->get('/de/pages/english-page')->assertRedirect(route('localized.pages.show', [
                'locale' => 'en',
                'slug' => 'english-page',
            ]));

            $news = News::query()->create([
                'title' => 'Русская новость',
                'slug' => 'legacy-news',
                'excerpt' => 'Русский анонс',
                'body' => '<p>Русский текст</p>',
                'published_at' => now(),
                'is_published' => true,
            ]);
            $news->translations()->createMany([
                [
                    'locale' => 'ru',
                    'title' => 'Русская новость',
                    'slug' => 'russian-news',
                    'excerpt' => 'Русский анонс',
                    'body' => '<p>Русский текст</p>',
                ],
                [
                    'locale' => 'en',
                    'title' => 'English news',
                    'slug' => 'english-news',
                    'excerpt' => 'English excerpt',
                    'body' => '<p>English text</p>',
                ],
            ]);

            $resolver = $this->app->make(LocalizedContentResolver::class);
            $this->assertNull($resolver->findNewsTranslation('de', 'russian-news'));
            $this->assertSame('en', $resolver->newsTranslation($news->fresh(), 'de')?->locale);

            $server = GameServer::query()->firstOrFail();
            $server->translations()->updateOrCreate(['locale' => 'ru'], ['name' => 'Русский сервер']);
            $server->translations()->updateOrCreate(['locale' => 'en'], ['name' => 'English server']);
            $this->assertSame('English server', $server->fresh()->nameFor('de'));

            $this->app->make(CmsSettings::class)->setMany([
                'site.name' => 'Русское старое название',
                'site.name.ru' => 'Русское название',
                'site.name.en' => 'English site',
            ]);

            $this->assertSame('English site', $this->app->make(SiteSettings::class)->name('de'));
            $this->assertSame(
                'Confirm your registration on {{site_name}}',
                $this->app->make(MailTemplateSettings::class)
                    ->values(MailTemplateSettings::EMAIL_VERIFICATION, 'de')['subject'],
            );
        } finally {
            File::delete($jsonPath);
            File::deleteDirectory($metadataDirectory);
            $this->forgetLocalizedServices();
        }
    }

    private function forgetLocalizedServices(bool $forgetLanguageManager = true): void
    {
        if ($forgetLanguageManager) {
            $this->app->forgetInstance(LanguageManager::class);
        }

        $this->app->forgetInstance(LocalizedContentResolver::class);
        $this->app->forgetInstance(SiteSettings::class);
        $this->app->forgetInstance(MailTemplateSettings::class);
    }
}
