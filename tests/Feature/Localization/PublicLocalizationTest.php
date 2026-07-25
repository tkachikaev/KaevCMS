<?php

namespace Tests\Feature\Localization;

use App\Models\News;
use App\Models\NewsTranslation;
use App\Models\Page;
use App\Models\PageTranslation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PublicLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_russian_and_english_public_routes_are_available(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Главная')
            ->assertSee('Новости');

        $this->get('/en')
            ->assertOk()
            ->assertSee('Home')
            ->assertSee('News')
            ->assertSee('Free open-source CMS for Lineage II servers.');

        $this->get('/en/login')
            ->assertOk()
            ->assertSee('Sign in')
            ->assertSee('Username or email');
    }

    public function test_language_switch_redirects_to_the_same_localized_path(): void
    {
        $this->get(route('language.switch', [
            'locale' => 'en',
            'return' => '/news?page=2',
        ]))
            ->assertRedirect(url('/en/news?page=2'))
            ->assertSessionHas('locale', 'en');
    }

    public function test_unknown_or_disabled_locales_are_not_exposed(): void
    {
        $this->get('/de')->assertNotFound();
        $this->get('/language/de')->assertNotFound();
    }

    public function test_language_switch_updates_authenticated_user_preference(): void
    {
        $user = User::query()->create([
            'name' => 'localized_player',
            'email' => 'localized@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123'),
            'is_active' => true,
            'locale' => 'ru',
        ]);

        $this->actingAs($user)
            ->get(route('language.switch', ['locale' => 'en', 'return' => '/account']))
            ->assertRedirect(url('/en/account'));

        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_news_can_have_different_slugs_and_content_per_language(): void
    {
        $news = News::query()->create([
            'title' => 'Русская новость',
            'slug' => 'russian-news',
            'excerpt' => 'Русское описание',
            'body' => '<p>Русский текст новости.</p>',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);

        NewsTranslation::query()->create([
            'news_id' => $news->id,
            'locale' => 'ru',
            'title' => 'Русская новость',
            'slug' => 'russian-news',
            'excerpt' => 'Русское описание',
            'body' => '<p>Русский текст новости.</p>',
        ]);
        NewsTranslation::query()->create([
            'news_id' => $news->id,
            'locale' => 'en',
            'title' => 'English news',
            'slug' => 'english-news',
            'excerpt' => 'English description',
            'body' => '<p>English news body.</p>',
        ]);

        $this->get('/ru/news/russian-news')
            ->assertOk()
            ->assertSee('Русская новость')
            ->assertSee('Русский текст новости.');

        $this->get('/en/news/english-news')
            ->assertOk()
            ->assertSee('English news')
            ->assertSee('English news body.')
            ->assertDontSee('Русский текст новости.');

        $this->get('/en/news')
            ->assertOk()
            ->assertSee('/en/news/english-news', false);
    }

    public function test_pages_redirect_to_the_actual_translation_and_expose_canonical_links(): void
    {
        $page = Page::query()->create([
            'slug' => 'pravila-servera',
            'is_published' => true,
            'show_in_header' => false,
            'show_in_footer' => false,
            'sort_order' => 10,
        ]);

        PageTranslation::query()->create([
            'page_id' => $page->id,
            'locale' => 'ru',
            'title' => 'Правила сервера',
            'slug' => 'pravila-servera',
            'body' => '<p>Русские правила.</p>',
        ]);
        PageTranslation::query()->create([
            'page_id' => $page->id,
            'locale' => 'en',
            'title' => 'Server rules',
            'slug' => 'server-rules',
            'body' => '<p>English rules.</p>',
        ]);

        $this->get('/pages/pravila-servera')
            ->assertRedirect(route('localized.pages.show', ['locale' => 'ru', 'slug' => 'pravila-servera']));

        $this->get('/en/pages/pravila-servera')
            ->assertRedirect(route('localized.pages.show', ['locale' => 'en', 'slug' => 'server-rules']))
            ->assertStatus(301);

        $this->get('/en/pages/server-rules')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/en/pages/server-rules').'">', false)
            ->assertSee('<link rel="alternate" hreflang="ru" href="'.url('/ru/pages/pravila-servera').'">', false)
            ->assertSee('<link rel="alternate" hreflang="en" href="'.url('/en/pages/server-rules').'">', false)
            ->assertSee('<link rel="alternate" hreflang="x-default" href="'.url('/ru/pages/pravila-servera').'">', false);

        $this->get(route('language.switch', [
            'locale' => 'en',
            'return' => '/ru/pages/pravila-servera',
        ]))->assertRedirect(url('/en/pages/server-rules'));

        $russianOnly = Page::query()->create([
            'slug' => 'politika',
            'is_published' => true,
            'show_in_header' => false,
            'show_in_footer' => false,
            'sort_order' => 20,
        ]);
        PageTranslation::query()->create([
            'page_id' => $russianOnly->id,
            'locale' => 'ru',
            'title' => 'Политика',
            'slug' => 'politika',
            'body' => '<p>Только русский перевод.</p>',
        ]);

        $this->get('/en/pages/politika')
            ->assertRedirect(route('localized.pages.show', ['locale' => 'ru', 'slug' => 'politika']))
            ->assertStatus(302);

        $this->get('/ru/pages/politika')
            ->assertOk()
            ->assertSee('<link rel="alternate" hreflang="ru" href="'.url('/ru/pages/politika').'">', false)
            ->assertDontSee('<link rel="alternate" hreflang="en"', false);
    }

    public function test_news_redirect_to_the_actual_translation_and_expose_canonical_links(): void
    {
        $news = News::query()->create([
            'title' => 'Русская новость',
            'slug' => 'novost-servera',
            'excerpt' => 'Описание',
            'body' => '<p>Русский текст.</p>',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);

        NewsTranslation::query()->create([
            'news_id' => $news->id,
            'locale' => 'ru',
            'title' => 'Русская новость',
            'slug' => 'novost-servera',
            'excerpt' => 'Описание',
            'body' => '<p>Русский текст.</p>',
        ]);
        NewsTranslation::query()->create([
            'news_id' => $news->id,
            'locale' => 'en',
            'title' => 'Server news',
            'slug' => 'server-news',
            'excerpt' => 'Description',
            'body' => '<p>English text.</p>',
        ]);

        $this->get('/news/novost-servera')
            ->assertRedirect(route('localized.news.show', ['locale' => 'ru', 'slug' => 'novost-servera']));

        $this->get('/en/news/novost-servera')
            ->assertRedirect(route('localized.news.show', ['locale' => 'en', 'slug' => 'server-news']))
            ->assertStatus(301);

        $this->get('/en/news/server-news')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/en/news/server-news').'">', false)
            ->assertSee('<link rel="alternate" hreflang="ru" href="'.url('/ru/news/novost-servera').'">', false)
            ->assertSee('<link rel="alternate" hreflang="en" href="'.url('/en/news/server-news').'">', false)
            ->assertSee('<link rel="alternate" hreflang="x-default" href="'.url('/ru/news/novost-servera').'">', false);

        $russianOnly = News::query()->create([
            'title' => 'Только русский',
            'slug' => 'tolko-russkiy',
            'excerpt' => 'Описание',
            'body' => '<p>Русский текст.</p>',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        NewsTranslation::query()->create([
            'news_id' => $russianOnly->id,
            'locale' => 'ru',
            'title' => 'Только русский',
            'slug' => 'tolko-russkiy',
            'excerpt' => 'Описание',
            'body' => '<p>Русский текст.</p>',
        ]);

        $this->get('/en/news/tolko-russkiy')
            ->assertRedirect(route('localized.news.show', ['locale' => 'ru', 'slug' => 'tolko-russkiy']))
            ->assertStatus(302);
    }
}
