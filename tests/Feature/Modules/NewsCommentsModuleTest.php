<?php

namespace Tests\Feature\Modules;

use App\Models\Admin;
use App\Models\News;
use App\Models\User;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleNavigationRegistry;
use App\Support\Modules\ModuleRuntime;
use App\Support\News\NewsExtensionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KaevCMS\Modules\NewsComments\Services\NewsCommentSettings;
use Tests\TestCase;

class NewsCommentsModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $module = app(ModuleManager::class)->enable('news-comments');
        app(ModuleRuntime::class)->bootModule($module);
    }

    public function test_installation_registers_schema_navigation_and_owner_only_mutations(): void
    {
        $module = app(ModuleManager::class)->inspect('news-comments');

        $this->assertTrue($module['valid'], implode(PHP_EOL, $module['errors']));
        $this->assertTrue($module['compatible'], implode(PHP_EOL, $module['errors']));
        $this->assertSame([], $module['pending_migrations']);

        foreach ([
            'module_news_comment_settings',
            'module_news_comment_news_settings',
            'module_news_comments',
            'module_news_comment_submission_events',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertDatabaseHas('module_news_comment_settings', [
            'id' => 1,
            'enabled' => false,
            'publication_mode' => 'manual_review',
            'allow_user_editing' => true,
            'allow_editor_moderation' => false,
        ]);
        $this->assertContains(
            'admin.module-pages.news-comments.index',
            array_column(app(ModuleNavigationRegistry::class)->adminLinks(), 'route'),
        );
        $this->assertTrue(app(ModuleAdminAccessRegistry::class)
            ->isRegistered('admin.module-pages.news-comments.settings.update'));
    }

    public function test_owner_can_save_settings_and_unauthorized_staff_cannot(): void
    {
        $owner = Admin::factory()->create();
        $admin = Admin::factory()->administrator()->create();
        $auditor = Admin::factory()->auditor()->create();
        $editor = Admin::factory()->editor()->create();
        $settings = [
            'enabled' => '1',
            'publication_mode' => 'automatic',
            'allow_user_editing' => '1',
            'allow_editor_moderation' => '1',
            'max_comments_per_day' => 35,
            'max_comments_per_news_per_day' => 6,
            'min_submission_interval_seconds' => 60,
            'max_comment_length' => 1500,
        ];

        foreach ([$admin, $auditor, $editor] as $staff) {
            $this->actingAs($staff, 'admin')->put('/admin/extensions/news-comments/settings', $settings)
                ->assertForbidden();
        }
        $this->assertDatabaseHas('module_news_comment_settings', ['id' => 1, 'enabled' => false]);

        $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/news-comments')
            ->assertOk();
        $this->actingAs($owner, 'admin')
            ->put('/admin/extensions/news-comments/settings', $settings)
            ->assertRedirect();

        $this->assertDatabaseHas('module_news_comment_settings', [
            'id' => 1,
            'enabled' => true,
            'publication_mode' => 'automatic',
            'max_comments_per_day' => 35,
            'allow_editor_moderation' => true,
        ]);
        $this->actingAs($editor, 'admin')->get('/admin/extensions/news-comments')->assertOk();
        $this->actingAs($editor, 'admin')->put('/admin/extensions/news-comments/settings', $settings)
            ->assertForbidden();
        $this->actingAs($auditor, 'admin')->get('/admin/extensions/news-comments')->assertOk();
    }

    public function test_per_article_policy_is_owner_only_and_global_switch_is_authoritative(): void
    {
        $news = $this->news();
        $owner = Admin::factory()->create();
        $admin = Admin::factory()->administrator()->create();
        $settings = app(NewsCommentSettings::class);

        $this->actingAs($admin, 'admin')->put(
            '/admin/extensions/news-comments/news/'.$news->id,
            ['policy' => 'enabled'],
        )->assertForbidden();

        $this->actingAs($owner, 'admin')->put(
            '/admin/extensions/news-comments/news/'.$news->id,
            ['policy' => 'enabled'],
        )->assertRedirect();

        $this->assertDatabaseHas('module_news_comment_news_settings', [
            'news_id' => $news->id,
            'policy' => 'enabled',
        ]);
        $this->assertFalse($settings->enabledForNews($news));

        $settings->update([...NewsCommentSettings::defaults(), 'enabled' => true], $owner);
        $this->assertTrue($settings->enabledForNews($news));

        $this->actingAs($owner, 'admin')->put(
            '/admin/extensions/news-comments/news/'.$news->id,
            ['policy' => 'invalid'],
        )->assertSessionHasErrors('policy');
        $this->actingAs($owner, 'admin')->put(
            '/admin/extensions/news-comments/news/'.$news->id,
            ['policy' => 'inherit'],
        )->assertRedirect();

        $this->assertDatabaseMissing('module_news_comment_news_settings', [
            'news_id' => $news->id,
        ]);
        $this->assertTrue($settings->enabledForNews($news));
    }

    public function test_public_themes_expose_optional_module_extension_slot(): void
    {
        foreach (['default', 'kaev-aurelia'] as $theme) {
            $source = (string) file_get_contents(base_path('themes/'.$theme.'/views/news/show.blade.php'));
            $this->assertStringContainsString(
                "@include('news.extensions', ['news' => ".'$news'.'])',
                $source,
                $theme,
            );
        }
    }

    public function test_invalid_settings_fail_closed_and_cannot_be_saved_invalidly(): void
    {
        $owner = Admin::factory()->create();
        $this->actingAs($owner, 'admin')->put('/admin/extensions/news-comments/settings', [
            'enabled' => 1,
            'publication_mode' => 'untrusted',
            'allow_user_editing' => 1,
            'max_comments_per_day' => 0,
            'max_comments_per_news_per_day' => 1,
            'min_submission_interval_seconds' => 60,
            'max_comment_length' => 1000,
        ])->assertSessionHasErrors();

        $this->assertDatabaseHas('module_news_comment_settings', [
            'id' => 1,
            'publication_mode' => 'manual_review',
            'enabled' => false,
        ]);

        DB::table('module_news_comment_settings')->where('id', 1)->update([
            'publication_mode' => 'corrupted',
            'enabled' => true,
        ]);

        $this->assertNull(app(NewsCommentSettings::class)->values());
        $this->assertFalse(app(NewsCommentSettings::class)->editorCanModerate());
        $this->actingAs($owner, 'admin')->get('/admin/extensions/news-comments')
            ->assertOk()
            ->assertSee(__('module-news-comments::messages.unavailable'));
    }

    public function test_slot_is_hidden_by_default_and_respects_news_policy(): void
    {
        $news = $this->news();
        $registry = app(NewsExtensionRegistry::class);
        $settings = app(NewsCommentSettings::class);
        $owner = Admin::factory()->create();

        $this->assertSame([], $registry->views($news));

        $settings->update([...NewsCommentSettings::defaults(), 'enabled' => true], $owner);
        $this->assertCount(1, $registry->views($news));

        DB::table('module_news_comment_news_settings')->insert([
            'news_id' => $news->id,
            'policy' => 'disabled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame([], $registry->views($news));

        DB::table('module_news_comment_news_settings')->where('news_id', $news->id)
            ->update(['policy' => 'enabled']);
        $this->assertCount(1, $registry->views($news));

        $news->is_published = false;
        $this->assertSame([], $registry->views($news));
    }

    public function test_user_deletion_keeps_comments_but_deletes_submission_events(): void
    {
        $news = $this->news();
        $user = User::factory()->create();
        $id = $this->comment($news, $user);

        $this->event($news, $user);
        $user->delete();

        $this->assertDatabaseHas('module_news_comments', ['id' => $id, 'user_id' => null]);
        $this->assertDatabaseCount('module_news_comment_submission_events', 0);
    }

    public function test_deleting_a_parent_comment_cascades_its_replies(): void
    {
        $news = $this->news();
        $user = User::factory()->create();
        $root = $this->comment($news, $user);
        $this->comment($news, $user, $root);

        DB::table('module_news_comments')->where('id', $root)->delete();

        $this->assertDatabaseCount('module_news_comments', 0);
    }

    public function test_deleting_news_cascades_all_comment_data_even_with_module_disabled(): void
    {
        $news = $this->news();
        $user = User::factory()->create();
        $root = $this->comment($news, $user);
        $this->comment($news, $user, $root);
        $this->event($news, $user);

        DB::table('module_news_comment_news_settings')->insert([
            'news_id' => $news->id,
            'policy' => 'enabled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('module_news_comments')->where('id', $root)
            ->update(['pending_body' => 'An edit awaiting review', 'pending_edited_at' => now()]);

        app(ModuleManager::class)->disable('news-comments');
        $news->forceDelete();

        $this->assertDatabaseCount('module_news_comments', 0);
        $this->assertDatabaseCount('module_news_comment_news_settings', 0);
        $this->assertDatabaseCount('module_news_comment_submission_events', 0);
    }

    private function news(): News
    {
        return News::query()->forceCreate([
            'title' => 'Integration article',
            'slug' => 'news-comments-test-'.uniqid(),
            'body' => '<p>Article</p>',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
    }

    private function comment(News $news, User $user, ?int $parentId = null): int
    {
        return (int) DB::table('module_news_comments')->insertGetId([
            'news_id' => $news->id,
            'user_id' => $user->id,
            'parent_id' => $parentId,
            'body' => 'A comment',
            'status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function event(News $news, User $user): void
    {
        DB::table('module_news_comment_submission_events')->insert([
            'news_id' => $news->id,
            'user_id' => $user->id,
            'created_at' => now(),
        ]);
    }
}
