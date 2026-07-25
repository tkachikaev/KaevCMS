<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\News;
use App\Models\NewsTranslation;
use App\Services\News\NewsImageStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminNewsManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $uploadRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploadRoot = storage_path('framework/testing/news-uploads');
        File::deleteDirectory($this->uploadRoot);
        config()->set('cms.news.uploads_path', $this->uploadRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->uploadRoot);

        parent::tearDown();
    }

    public function test_guest_cannot_open_news_management(): void
    {
        $this->get('/admin/news')
            ->assertRedirect(route('admin.login'));
    }

    public function test_admin_sees_empty_news_state(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get('/admin/news')
            ->assertOk()
            ->assertSee('Новостей пока нет')
            ->assertSee('Создать первую новость');
    }

    public function test_admin_news_index_is_paginated_by_ten_items(): void
    {
        $admin = $this->createAdmin();

        foreach (range(1, 11) as $number) {
            News::query()->forceCreate([
                'title' => sprintf('Новость %02d', $number),
                'slug' => sprintf('news-%02d', $number),
                'excerpt' => 'Описание',
                'body' => '<p>Текст</p>',
                'is_published' => false,
                'created_at' => now()->addSeconds($number),
                'updated_at' => now()->addSeconds($number),
            ]);
        }

        $this->actingAs($admin, 'admin')
            ->get('/admin/news')
            ->assertOk()
            ->assertSee('Новость 11')
            ->assertDontSee('Новость 01')
            ->assertSee('page=2', false);

        $this->actingAs($admin, 'admin')
            ->get('/admin/news?page=2')
            ->assertOk()
            ->assertSee('Новость 01');
    }

    public function test_admin_can_create_formatted_news_and_unsafe_html_is_removed(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/news', [
                'title' => 'Первая новость',
                'excerpt' => '',
                'body' => '<h2 data-align="center">Заголовок</h2><p onclick="alert(1)">Текст <strong>важный</strong><script>alert(1)</script> <span data-color="gold">золотой</span></p><iframe src="https://example.com"></iframe>',
                'published_at' => '2026-07-12 18:00:00',
                'is_published' => '0',
                'remove_cover_image' => '0',
            ])
            ->assertRedirect(route('admin.news.index'))
            ->assertSessionHas('status');

        $createdNews = News::query()->where('title', 'Первая новость')->firstOrFail();

        $this->assertStringContainsString('<strong>важный</strong>', $createdNews->body);
        $this->assertStringContainsString('data-color="gold"', $createdNews->body);
        $this->assertStringNotContainsString('<script', $createdNews->body);
        $this->assertStringNotContainsString('<iframe', $createdNews->body);
        $this->assertStringNotContainsString('onclick', $createdNews->body);
        $this->assertNotSame('', $createdNews->slug);
        $this->assertStringContainsString('Заголовок', $createdNews->excerpt);
    }

    public function test_admin_can_preview_unsaved_news_in_active_theme(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/news/preview', [
                'title' => 'Новость без сохранения',
                'excerpt' => 'Описание',
                'body' => '<p>Безопасный <strong>текст</strong></p><script>alert(1)</script>',
                'cover_image' => $this->pngUpload('preview.png'),
                'published_at' => now()->format('Y-m-d H:i:s'),
                'is_published' => '0',
                'remove_cover_image' => '0',
            ])
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('Предпросмотр новости')
            ->assertSee('Новость без сохранения')
            ->assertSee('<strong>текст</strong>', false)
            ->assertSee('data:image/png;base64,', false)
            ->assertDontSee('alert(1)', false);

        $this->assertDatabaseCount('news', 0);
    }

    public function test_admin_can_upload_cover_image(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/news', [
                'title' => 'Новость с обложкой',
                'excerpt' => 'Описание',
                'body' => '<p>Текст новости.</p>',
                'cover_image' => $this->pngUpload('cover.png'),
                'published_at' => now()->subMinute()->format('Y-m-d H:i:s'),
                'is_published' => '1',
                'remove_cover_image' => '0',
            ])
            ->assertRedirect(route('admin.news.index'));

        $news = News::query()->where('title', 'Новость с обложкой')->firstOrFail();

        $this->assertNotNull($news->image);
        $this->assertMatchesRegularExpression('~^news/covers/\d{4}/\d{2}/[a-f0-9-]+\.png$~', $news->image);
        $this->assertFileExists($this->absoluteUploadPath($news->image));

        $translation = $news->translations()->where('locale', 'ru')->firstOrFail();

        $this->get('/news/'.$news->slug)
            ->assertRedirect(route('localized.news.show', [
                'locale' => $translation->locale,
                'slug' => $translation->slug,
            ]));

        $this->get('/ru/news/'.$translation->slug)
            ->assertOk()
            ->assertSee('/uploads/'.$news->image, false);
    }

    public function test_admin_can_upload_an_inline_news_image(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin, 'admin')
            ->withHeader('Accept', 'application/json')
            ->post('/admin/news/images', [
                'image' => $this->pngUpload('inside.png'),
            ])
            ->assertCreated()
            ->assertJsonStructure(['url', 'path']);

        $path = $response->json('path');
        $this->assertMatchesRegularExpression('~^news/content/\d{4}/\d{2}/[a-f0-9-]+\.png$~', $path);
        $this->assertSame('/uploads/'.$path, $response->json('url'));
        $this->assertFileExists($this->absoluteUploadPath($path));
    }

    public function test_svg_upload_is_rejected(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->withHeader('Accept', 'application/json')
            ->post('/admin/news/images', [
                'image' => UploadedFile::fake()->createWithContent(
                    'danger.svg',
                    '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
                ),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
    }

    public function test_admin_can_edit_and_publish_news(): void
    {
        $admin = $this->createAdmin();
        $news = News::query()->create([
            'title' => 'Черновик',
            'slug' => 'chernovik',
            'excerpt' => 'Черновик',
            'body' => '<p>Текст</p>',
            'is_published' => false,
        ]);

        $this->actingAs($admin, 'admin')
            ->put('/admin/news/'.$news->id, [
                'title' => 'Готовая новость',
                'excerpt' => 'Описание',
                'body' => '<p>Готовый <strong>текст</strong> новости.</p>',
                'published_at' => now()->subMinute()->format('Y-m-d H:i:s'),
                'is_published' => '1',
                'remove_cover_image' => '0',
            ])
            ->assertRedirect(route('admin.news.index'));

        $this->assertDatabaseHas('news', [
            'id' => $news->id,
            'title' => 'Готовая новость',
            'slug' => 'chernovik',
            'is_published' => true,
        ]);

        $translation = $news->fresh()->translations()->where('locale', 'ru')->firstOrFail();

        $this->get('/news/chernovik')
            ->assertRedirect(route('localized.news.show', [
                'locale' => $translation->locale,
                'slug' => $translation->slug,
            ]));

        $this->get('/ru/news/'.$translation->slug)
            ->assertOk()
            ->assertSee('Готовая новость')
            ->assertSee('<strong>текст</strong>', false);
    }

    public function test_removing_inline_image_from_news_deletes_unreferenced_file(): void
    {
        $admin = $this->createAdmin();
        $path = 'news/content/2026/07/123e4567-e89b-12d3-a456-426614174000.png';
        $absolutePath = $this->absoluteUploadPath($path);
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, 'inline image');

        $news = News::query()->create([
            'title' => 'Новость с картинкой',
            'slug' => 'news-with-old-image',
            'excerpt' => 'Описание',
            'body' => '<p>Текст</p><figure><img src="/uploads/'.$path.'" alt=""></figure>',
            'is_published' => false,
        ]);

        $this->actingAs($admin, 'admin')
            ->put('/admin/news/'.$news->id, [
                'title' => $news->title,
                'excerpt' => $news->excerpt,
                'body' => '<p>Текст без картинки</p>',
                'published_at' => '',
                'is_published' => '0',
                'remove_cover_image' => '0',
            ])
            ->assertRedirect(route('admin.news.index'));

        $this->assertFileDoesNotExist($absolutePath);
    }

    public function test_admin_can_delete_news_and_its_unreferenced_media(): void
    {
        $admin = $this->createAdmin();
        $coverPath = 'news/covers/2026/07/323e4567-e89b-12d3-a456-426614174000.png';
        $contentPath = 'news/content/2026/07/423e4567-e89b-12d3-a456-426614174000.png';

        foreach ([$coverPath, $contentPath] as $path) {
            File::ensureDirectoryExists(dirname($this->absoluteUploadPath($path)));
            File::put($this->absoluteUploadPath($path), 'image');
        }

        $news = News::query()->create([
            'title' => 'Новость для удаления',
            'slug' => 'delete-this-news',
            'excerpt' => 'Описание',
            'body' => '<p>Текст</p><figure><img src="/uploads/'.$contentPath.'" alt=""></figure>',
            'image' => $coverPath,
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/news')
            ->assertOk()
            ->assertSee('Редактировать')
            ->assertSee('Удалить')
            ->assertSee('Да, удалить')
            ->assertSee(route('admin.news.destroy', $news), false)
            ->assertDontSee('На сайте ↗');

        $this->actingAs($admin, 'admin')
            ->get('/admin/news/'.$news->id.'/edit')
            ->assertOk()
            ->assertDontSee('Удалить новость');

        $this->actingAs($admin, 'admin')
            ->delete('/admin/news/'.$news->id)
            ->assertRedirect(route('admin.news.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('news', ['id' => $news->id]);
        $this->assertFileDoesNotExist($this->absoluteUploadPath($coverPath));
        $this->assertFileDoesNotExist($this->absoluteUploadPath($contentPath));
        $this->get('/news/delete-this-news')->assertNotFound();
    }

    public function test_deleting_news_keeps_an_image_referenced_by_another_news_item(): void
    {
        $admin = $this->createAdmin();
        $contentPath = 'news/content/2026/07/523e4567-e89b-12d3-a456-426614174000.png';
        $absolutePath = $this->absoluteUploadPath($contentPath);
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, 'shared image');

        $first = News::query()->create([
            'title' => 'Первая',
            'slug' => 'first-shared',
            'excerpt' => 'Описание',
            'body' => '<p>Текст</p><img src="/uploads/'.$contentPath.'" alt="">',
            'is_published' => false,
        ]);

        News::query()->create([
            'title' => 'Вторая',
            'slug' => 'second-shared',
            'excerpt' => 'Описание',
            'body' => '<p>Текст</p><img src="/uploads/'.$contentPath.'" alt="">',
            'is_published' => false,
        ]);

        $this->actingAs($admin, 'admin')->delete('/admin/news/'.$first->id);

        $this->assertFileExists($absolutePath);
    }

    public function test_inline_image_referenced_only_by_translation_is_not_deleted(): void
    {
        $contentPath = 'news/content/2026/07/623e4567-e89b-12d3-a456-426614174001.png';
        $absolutePath = $this->absoluteUploadPath($contentPath);
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, 'translated image');

        $news = News::query()->create([
            'title' => 'Legacy title',
            'slug' => 'translated-image',
            'excerpt' => 'Legacy excerpt',
            'body' => '<p>Legacy body without image</p>',
            'is_published' => false,
        ]);
        NewsTranslation::query()->create([
            'news_id' => $news->id,
            'locale' => 'en',
            'title' => 'Translated image',
            'slug' => 'translated-image-en',
            'excerpt' => 'Description',
            'body' => '<p>Text</p><img src="/uploads/'.$contentPath.'" alt="">',
        ]);

        $deleted = app(NewsImageStorage::class)
            ->deleteIfUnreferenced($contentPath);

        $this->assertFalse($deleted);
        $this->assertFileExists($absolutePath);
    }

    public function test_cleanup_command_removes_old_unreferenced_cover_and_content_images(): void
    {
        $paths = [
            'referenced_content' => 'news/content/2026/07/123e4567-e89b-12d3-a456-426614174000.png',
            'orphan_content' => 'news/content/2026/07/223e4567-e89b-12d3-a456-426614174000.png',
            'referenced_cover' => 'news/covers/2026/07/623e4567-e89b-12d3-a456-426614174000.png',
            'orphan_cover' => 'news/covers/2026/07/723e4567-e89b-12d3-a456-426614174000.png',
        ];

        foreach ($paths as $path) {
            $absolutePath = $this->absoluteUploadPath($path);
            File::ensureDirectoryExists(dirname($absolutePath));
            File::put($absolutePath, 'image');
            touch($absolutePath, now()->subDays(2)->getTimestamp());
        }

        News::query()->create([
            'title' => 'Новость с картинками',
            'slug' => 'news-with-images',
            'excerpt' => 'Описание',
            'body' => '<p>Текст</p><figure><img src="/uploads/'.$paths['referenced_content'].'" alt=""></figure>',
            'image' => $paths['referenced_cover'],
            'is_published' => false,
        ]);

        $this->artisan('kaevcms:news-media-clean', ['--hours' => 1])
            ->assertSuccessful();

        $this->assertFileExists($this->absoluteUploadPath($paths['referenced_content']));
        $this->assertFileExists($this->absoluteUploadPath($paths['referenced_cover']));
        $this->assertFileDoesNotExist($this->absoluteUploadPath($paths['orphan_content']));
        $this->assertFileDoesNotExist($this->absoluteUploadPath($paths['orphan_cover']));
    }

    public function test_upgrade_migration_converts_legacy_plain_text_news_from_0_5_0(): void
    {
        DB::table('news')->insert([
            'title' => 'Старая новость',
            'slug' => 'legacy-news',
            'excerpt' => 'Описание',
            'body' => "Первый абзац\n\nВторой абзац\nс новой строки",
            'is_published' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_07_12_000400_convert_news_body_to_safe_html.php');
        $migration->up();

        $body = (string) DB::table('news')->where('slug', 'legacy-news')->value('body');

        $this->assertSame(
            "<p>Первый абзац</p>\n<p>Второй абзац<br>с новой строки</p>",
            $body
        );
    }

    public function test_public_render_sanitizes_body_again_as_defence_in_depth(): void
    {
        News::query()->create([
            'title' => 'Проверка безопасности',
            'slug' => 'security-check',
            'excerpt' => 'Описание',
            'body' => '<p>Безопасный текст</p><script>alert(1)</script><img src="https://example.com/tracker.png" onerror="alert(2)">',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->get('/news/security-check')
            ->assertOk()
            ->assertSee('Безопасный текст')
            ->assertDontSee('alert(1)', false)
            ->assertDontSee('tracker.png', false)
            ->assertDontSee('onerror', false);
    }

    public function test_draft_is_not_visible_on_public_site(): void
    {
        News::query()->create([
            'title' => 'Скрытая новость',
            'slug' => 'hidden-news',
            'excerpt' => 'Описание',
            'body' => '<p>Текст</p>',
            'is_published' => false,
            'published_at' => now()->subMinute(),
        ]);

        $this->get('/news/hidden-news')->assertNotFound();
        $this->get('/news')->assertDontSee('Скрытая новость');
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

    private function pngUpload(string $name): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2V1sAAAAASUVORK5CYII=', true);

        return UploadedFile::fake()->createWithContent($name, $png ?: '');
    }

    private function absoluteUploadPath(string $path): string
    {
        return $this->uploadRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
