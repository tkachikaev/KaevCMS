<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\CmsSetting;
use App\Models\News;
use App\Services\NewsDisplaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class NewsSettingsTest extends TestCase
{
    use RefreshDatabase;

    private string $uploadRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploadRoot = storage_path('framework/testing/news-settings-uploads');
        File::deleteDirectory($this->uploadRoot);
        config()->set('cms.settings.uploads_path', $this->uploadRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->uploadRoot);

        parent::tearDown();
    }

    public function test_news_page_links_to_the_news_settings_tab(): void
    {
        $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.news.index'))
            ->assertOk()
            ->assertSee(route('admin.settings.news'), false)
            ->assertSee(__('Go to settings'));

        $this->get(route('admin.settings.news'))
            ->assertOk()
            ->assertSee('name="home_limit"', false)
            ->assertSee('name="per_page"', false)
            ->assertSee('name="default_cover"', false);
    }

    public function test_news_counts_are_saved_and_applied_on_the_website(): void
    {
        foreach (range(1, 7) as $number) {
            $this->article($number);
        }

        $this->get('/')->assertOk()
            ->assertSee('Display news 5')
            ->assertDontSee('Display news 4');

        $this->actingAs($this->createAdmin(), 'admin')
            ->put(route('admin.settings.news.update'), ['home_limit' => '5', 'per_page' => '6'])
            ->assertRedirect(route('admin.settings.news'));

        $this->assertSame('5', $this->setting(NewsDisplaySettings::KEY_HOME_LIMIT));
        $this->assertSame('6', $this->setting(NewsDisplaySettings::KEY_PER_PAGE));

        $this->get('/')->assertOk()
            ->assertSee('Display news 3')
            ->assertDontSee('Display news 2');
        $this->get('/news')->assertOk()
            ->assertSee('Display news 2')
            ->assertDontSee('Display news 1<', false);
        $this->get('/news?page=2')->assertOk()
            ->assertSee('Display news 1');
    }

    public function test_default_cover_is_shown_for_news_without_an_image_and_can_be_removed(): void
    {
        $article = $this->article(1);
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->put(route('admin.settings.news.update'), [
                'home_limit' => '3',
                'per_page' => '10',
                'default_cover' => $this->pngUpload(),
            ])
            ->assertRedirect(route('admin.settings.news'));

        $cover = (string) $this->setting(NewsDisplaySettings::KEY_DEFAULT_COVER);
        $this->assertMatchesRegularExpression('~^settings/news-cover/[a-f0-9-]+\.png$~', $cover);
        $this->assertFileExists($this->uploadRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $cover));
        $this->assertNull($article->fresh()->coverUrl());
        $this->assertStringEndsWith('/uploads/'.$cover, (string) $article->fresh()->displayCoverUrl());
        $this->get('/news')->assertOk()->assertSee('/uploads/'.$cover, false);

        $this->put(route('admin.settings.news.update'), [
            'home_limit' => '3',
            'per_page' => '10',
            'remove_default_cover' => '1',
        ])->assertRedirect(route('admin.settings.news'));

        $this->assertNull($this->setting(NewsDisplaySettings::KEY_DEFAULT_COVER));
        $this->assertFileDoesNotExist($this->uploadRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $cover));
        $this->assertNull($article->fresh()->displayCoverUrl());
    }

    public function test_values_outside_their_range_are_rejected(): void
    {
        $this->actingAs($this->createAdmin(), 'admin')
            ->from(route('admin.settings.news'))
            ->put(route('admin.settings.news.update'), ['home_limit' => '0', 'per_page' => '500'])
            ->assertRedirect(route('admin.settings.news'))
            ->assertSessionHasErrors(['home_limit', 'per_page']);

        $this->assertNull($this->setting(NewsDisplaySettings::KEY_HOME_LIMIT));
        $this->assertSame(3, app(NewsDisplaySettings::class)->homeLimit());
        $this->assertSame(10, app(NewsDisplaySettings::class)->perPage());
    }

    private function article(int $number): News
    {
        return News::query()->forceCreate([
            'title' => 'Display news '.$number,
            'slug' => 'display-news-'.$number,
            'body' => '<p>Body</p>',
            'is_published' => true,
            'published_at' => now()->subMinutes(10 - $number),
        ]);
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'name' => 'Main Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
        ]);
    }

    private function pngUpload(): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2V1sAAAAASUVORK5CYII=', true);

        return UploadedFile::fake()->createWithContent('cover.png', $png ?: '');
    }

    private function setting(string $key): ?string
    {
        return CmsSetting::query()->where('key', $key)->value('value');
    }
}
