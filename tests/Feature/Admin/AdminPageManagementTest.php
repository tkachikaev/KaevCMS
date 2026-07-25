<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Page;
use App\Services\Localization\LanguageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminPageManagementTest extends TestCase
{
    use RefreshDatabase;

    private string $uploadRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploadRoot = storage_path('framework/testing/page-uploads');
        File::deleteDirectory($this->uploadRoot);
        config()->set('cms.pages.uploads_path', $this->uploadRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->uploadRoot);

        parent::tearDown();
    }

    public function test_guest_cannot_open_page_management(): void
    {
        $this->get('/admin/pages')
            ->assertRedirect(route('admin.login'));
    }

    public function test_administrator_can_create_multilingual_page_and_add_it_to_navigation(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/pages', [
                'translations' => [
                    'ru' => [
                        'title' => 'Правила сервера',
                        'slug' => 'pravila-servera',
                        'body' => '<p>Соблюдайте <strong>правила</strong>.</p>',
                        'seo_title' => 'Правила Eternal World',
                        'seo_description' => 'Основные правила игрового сервера.',
                    ],
                    'en' => [
                        'title' => 'Server rules',
                        'slug' => 'server-rules',
                        'body' => '<p>Follow the <strong>rules</strong>.</p>',
                        'seo_title' => 'Eternal World rules',
                        'seo_description' => 'Main game server rules.',
                    ],
                ],
                'is_published' => '1',
                'show_in_header' => '1',
                'show_in_footer' => '1',
                'sort_order' => '10',
            ])
            ->assertRedirect(route('admin.pages.index'))
            ->assertSessionHas('status');

        $page = Page::query()->with('translations')->firstOrFail();

        $this->assertDatabaseHas('pages', [
            'id' => $page->id,
            'slug' => 'pravila-servera',
            'is_published' => true,
            'show_in_header' => true,
            'show_in_footer' => true,
            'sort_order' => 10,
        ]);
        $this->assertDatabaseHas('page_translations', [
            'page_id' => $page->id,
            'locale' => 'en',
            'slug' => 'server-rules',
            'title' => 'Server rules',
        ]);

        $this->get('/pages/pravila-servera')
            ->assertRedirect(route('localized.pages.show', [
                'locale' => 'ru',
                'slug' => 'pravila-servera',
            ]));

        $this->get('/ru/pages/pravila-servera')
            ->assertOk()
            ->assertSee('Правила сервера')
            ->assertSee('<strong>правила</strong>', false)
            ->assertSee('Основные правила игрового сервера.', false);

        $this->get('/en/pages/server-rules')
            ->assertOk()
            ->assertSee('Server rules')
            ->assertSee('<strong>rules</strong>', false)
            ->assertSee('Main game server rules.', false);

        $this->get('/language/en?return='.urlencode('/ru/pages/pravila-servera'))
            ->assertRedirect(route('localized.pages.show', [
                'locale' => 'en',
                'slug' => 'server-rules',
            ]));

        $this->get('/')
            ->assertOk()
            ->assertSee('Server rules');

        $this->get('/language/ru?return='.urlencode('/'))
            ->assertRedirect(route('localized.home', ['locale' => 'ru']));

        $this->get('/ru')
            ->assertOk()
            ->assertSee('Правила сервера');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'page.created',
            'result' => 'success',
        ]);
    }

    public function test_administrator_can_preview_unsaved_page_from_create_and_edit_forms(): void
    {
        $admin = $this->createAdmin();
        $payload = [
            'translations' => [
                'ru' => [
                    'title' => 'Предпросмотр правил',
                    'slug' => 'preview-rules',
                    'body' => '<p>Текст <strong>предпросмотра</strong>.</p>',
                    'seo_title' => 'SEO предпросмотра',
                    'seo_description' => 'Описание предпросмотра.',
                ],
                'en' => ['title' => '', 'slug' => '', 'body' => '', 'seo_title' => '', 'seo_description' => ''],
            ],
            'preview_locale' => 'ru',
            'is_published' => '0',
            'show_in_header' => '0',
            'show_in_footer' => '1',
            'sort_order' => '100',
        ];

        $this->actingAs($admin, 'admin')
            ->post('/admin/pages/preview', $payload)
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('Предпросмотр страницы')
            ->assertSee('Предпросмотр правил')
            ->assertSee('<strong>предпросмотра</strong>', false);

        // Edit forms contain a hidden _method=PUT field. The preview route must
        // accept that method too, even if browser JavaScript is stale or disabled.
        $this->actingAs($admin, 'admin')
            ->put('/admin/pages/preview', $payload)
            ->assertOk()
            ->assertSee('Предпросмотр правил')
            ->assertSee('<strong>предпросмотра</strong>', false);

        $this->assertDatabaseCount('pages', 0);
        $this->assertDatabaseCount('page_translations', 0);
    }

    public function test_page_forms_use_styled_delete_dialog_and_clean_footer_checkbox_layout(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get('/admin/pages')
            ->assertOk()
            ->assertSee('confirm-dialog-card', false)
            ->assertSee('confirm-dialog-copy', false)
            ->assertSee('confirm-dialog-mark', false);

        $this->actingAs($admin, 'admin')
            ->get('/admin/pages/create')
            ->assertOk()
            ->assertSee('switch-row switch-row-spaced', false)
            ->assertDontSee('switch-row form-group compact', false);
    }

    public function test_draft_page_is_not_public_and_is_not_shown_in_navigation(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')->post('/admin/pages', [
            'translations' => [
                'ru' => [
                    'title' => 'Скрытая страница',
                    'slug' => 'hidden-page',
                    'body' => '<p>Черновик</p>',
                    'seo_title' => '',
                    'seo_description' => '',
                ],
                'en' => ['title' => '', 'slug' => '', 'body' => '', 'seo_title' => '', 'seo_description' => ''],
            ],
            'is_published' => '0',
            'show_in_header' => '1',
            'show_in_footer' => '1',
            'sort_order' => '100',
        ])->assertRedirect(route('admin.pages.index'));

        $this->get('/pages/hidden-page')->assertNotFound();
        $this->get('/')->assertDontSee('Скрытая страница');
    }

    public function test_page_html_is_sanitized_and_page_can_be_updated_and_deleted(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')->post('/admin/pages', [
            'translations' => [
                'ru' => [
                    'title' => 'Контакты',
                    'slug' => 'contacts',
                    'body' => '<p onclick="alert(1)">Связь</p><script>alert(2)</script>',
                    'seo_title' => '',
                    'seo_description' => '',
                ],
                'en' => ['title' => '', 'slug' => '', 'body' => '', 'seo_title' => '', 'seo_description' => ''],
            ],
            'is_published' => '1',
            'show_in_header' => '0',
            'show_in_footer' => '1',
            'sort_order' => '20',
        ]);

        $page = Page::query()->firstOrFail();
        $translation = $page->translations()->where('locale', 'ru')->firstOrFail();
        $this->assertStringContainsString('<p>Связь</p>', $translation->body);
        $this->assertStringNotContainsString('onclick', $translation->body);
        $this->assertStringNotContainsString('script', $translation->body);

        $this->actingAs($admin, 'admin')->put('/admin/pages/'.$page->id, [
            'translations' => [
                'ru' => [
                    'title' => 'Наши контакты',
                    'slug' => 'our-contacts',
                    'body' => '<p>Напишите администратору.</p>',
                    'seo_title' => '',
                    'seo_description' => '',
                ],
                'en' => ['title' => '', 'slug' => '', 'body' => '', 'seo_title' => '', 'seo_description' => ''],
            ],
            'is_published' => '1',
            'show_in_header' => '1',
            'show_in_footer' => '0',
            'sort_order' => '5',
        ])->assertRedirect(route('admin.pages.index'));

        $this->assertDatabaseHas('pages', [
            'id' => $page->id,
            'slug' => 'our-contacts',
            'show_in_header' => true,
            'show_in_footer' => false,
            'sort_order' => 5,
        ]);
        $this->get('/pages/contacts')->assertNotFound();
        $this->get('/pages/our-contacts')
            ->assertRedirect(route('localized.pages.show', [
                'locale' => 'ru',
                'slug' => 'our-contacts',
            ]));
        $this->get('/ru/pages/our-contacts')->assertOk()->assertSee('Наши контакты');

        $this->actingAs($admin, 'admin')
            ->delete('/admin/pages/'.$page->id)
            ->assertRedirect(route('admin.pages.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
        $this->get('/pages/our-contacts')->assertNotFound();
    }

    public function test_newly_enabled_language_automatically_appears_in_page_editor(): void
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
        File::put($jsonPath, json_encode(['Pages' => 'Seiten'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        try {
            $this->app->forgetInstance(LanguageManager::class);
            $languages = $this->app->make(LanguageManager::class);
            $languages->update(['ru', 'en', 'de'], 'ru', 'en');

            $admin = $this->createAdmin();
            $this->actingAs($admin, 'admin')
                ->get('/admin/pages/create')
                ->assertOk()
                ->assertSee('Deutsch');

            $this->actingAs($admin, 'admin')->post('/admin/pages', [
                'translations' => [
                    'ru' => [
                        'title' => 'Правила', 'slug' => 'rules-ru', 'body' => '<p>Правила</p>',
                        'seo_title' => '', 'seo_description' => '',
                    ],
                    'en' => [
                        'title' => 'Rules', 'slug' => 'rules-en', 'body' => '<p>Rules</p>',
                        'seo_title' => '', 'seo_description' => '',
                    ],
                    'de' => [
                        'title' => 'Regeln', 'slug' => 'regeln', 'body' => '<p>Deutsche Regeln</p>',
                        'seo_title' => '', 'seo_description' => '',
                    ],
                ],
                'is_published' => '1',
                'show_in_header' => '0',
                'show_in_footer' => '0',
                'sort_order' => '100',
            ])->assertRedirect(route('admin.pages.index'));

            $this->assertDatabaseHas('page_translations', [
                'locale' => 'de',
                'slug' => 'regeln',
                'title' => 'Regeln',
            ]);
            $this->get('/de/pages/regeln')
                ->assertOk()
                ->assertSee('Regeln')
                ->assertSee('Deutsche Regeln');
        } finally {
            File::delete($jsonPath);
            File::deleteDirectory($metadataDirectory);
            $this->app->forgetInstance(LanguageManager::class);
        }
    }

    public function test_administrator_can_upload_an_image_for_page_content(): void
    {
        $admin = $this->createAdmin();

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZkVQAAAAASUVORK5CYII=',
            true,
        );
        $this->assertIsString($png);

        $response = $this->actingAs($admin, 'admin')
            ->post('/admin/pages/images', [
                'image' => UploadedFile::fake()->createWithContent('page.png', $png),
            ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('url', fn (string $url): bool => str_starts_with($url, '/uploads/pages/content/'));

        $path = (string) $response->json('path');
        $this->assertMatchesRegularExpression(
            '~^pages/content/\d{4}/\d{2}/[a-f0-9-]+\.png$~',
            $path,
        );
        $this->assertFileExists($this->absoluteUploadPath($path));
    }

    public function test_cleanup_command_removes_old_unreferenced_page_images(): void
    {
        $referencedPath = 'pages/content/2026/07/123e4567-e89b-12d3-a456-426614174000.png';
        $orphanPath = 'pages/content/2026/07/223e4567-e89b-12d3-a456-426614174000.png';

        foreach ([$referencedPath, $orphanPath] as $path) {
            $absolutePath = $this->absoluteUploadPath($path);
            File::ensureDirectoryExists(dirname($absolutePath));
            File::put($absolutePath, 'image');
            touch($absolutePath, now()->subDays(2)->getTimestamp());
        }

        $page = Page::query()->create([
            'slug' => 'page-with-image',
            'is_published' => false,
            'show_in_header' => false,
            'show_in_footer' => false,
            'sort_order' => 100,
        ]);
        $page->translations()->create([
            'locale' => 'ru',
            'title' => 'Страница с изображением',
            'slug' => 'page-with-image',
            'body' => '<p>Текст</p><figure><img src="/uploads/'.$referencedPath.'" alt=""></figure>',
            'seo_title' => null,
            'seo_description' => null,
        ]);

        $this->artisan('kaevcms:page-media-clean', ['--hours' => 1])
            ->assertSuccessful();

        $this->assertFileExists($this->absoluteUploadPath($referencedPath));
        $this->assertFileDoesNotExist($this->absoluteUploadPath($orphanPath));
    }

    private function absoluteUploadPath(string $path): string
    {
        return $this->uploadRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'name' => 'Main Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('CorrectPassword123'),
            'is_active' => true,
            'locale' => 'ru',
        ]);
    }
}
