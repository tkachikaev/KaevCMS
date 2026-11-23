<?php

namespace Tests\Feature\Modules;

use App\Models\Admin;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use KaevCMS\Modules\Forum\Models\ForumSection;
use KaevCMS\Modules\Forum\Models\ForumTopic;
use KaevCMS\Modules\Forum\Services\ForumActor;
use KaevCMS\Modules\Forum\Services\ForumPermissions;
use KaevCMS\Modules\Forum\Services\ForumPostChanges;
use KaevCMS\Modules\Forum\Services\ForumPosting;
use KaevCMS\Modules\Forum\Services\ForumStructure;
use KaevCMS\Modules\Forum\Services\ForumTopicModeration;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class ForumStabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $module = app(ModuleManager::class)->enable('forum');
        app(ModuleRuntime::class)->bootModule($module);
    }

    public function test_settings_row_is_locked_before_section_during_topic_creation(): void
    {
        $writer = User::factory()->create();
        $section = $this->section('Общее общение');
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            $queries[] = strtolower($event->sql);
        });

        app(ForumPosting::class)->createTopic(
            new ForumActor('user', user: $writer),
            (int) $section->id,
            'Consistent lock ordering',
            'Opening message',
            (string) Str::uuid()
        );

        // Exclude the preflight value('links_after_days') query: only the
        // transactional SELECT * locks the singleton row.
        $settingsIndex = null;
        $sectionIndex = null;
        foreach ($queries as $index => $sql) {
            if ($settingsIndex === null && str_contains($sql, 'select *')
                && str_contains($sql, 'module_forum_settings')) {
                $settingsIndex = $index;
            }
            if ($sectionIndex === null && str_contains($sql, 'select *')
                && str_contains($sql, 'module_forum_sections')) {
                $sectionIndex = $index;
            }
        }

        $this->assertNotNull($settingsIndex, 'Missing write-locked forum settings query.');
        $this->assertNotNull($sectionIndex, 'Missing write-locked forum section query.');
        $this->assertLessThan($sectionIndex, $settingsIndex);
    }

    public function test_link_age_policy_cannot_change_between_validation_and_insert(): void
    {
        $player = User::factory()->create();
        $actor = new ForumActor('user', user: $player);
        $posting = app(ForumPosting::class);

        [$body, $html] = $posting->prepareBody($actor, 'Useful https://example.org/link', null);
        $this->assertNull($html);

        // The first check happened under the old policy. Publishing must consult the
        // new value in the transaction, and take a lock until it commits.
        DB::table('module_forum_settings')->where('id', 1)->update(['links_after_days' => 7]);

        try {
            DB::transaction(fn () => $posting->assertCurrentContentPolicy($actor, $body));
            $this->fail('A seven-day delay must reject a newly published link.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('body', $exception->errors());
        }

        $this->assertDatabaseCount('module_forum_topics', 0);
    }

    public function test_stricter_link_policy_applies_to_new_topics_and_replies(): void
    {
        $writer = User::factory()->create();
        $section = $this->section('Общее общение');
        $actor = new ForumActor('user', user: $writer);
        $topic = $this->createTopic($writer, 'Original question');
        DB::table('module_forum_settings')->where('id', 1)->update(['links_after_days' => 7]);

        $this->actingAs($writer, 'web')->withSession(['forum.author_mode' => 'user']);
        $this->post('/forum/sections/'.$section->id.'/topics', [
            'title' => 'Link topic', 'body' => 'Check https://example.org',
            'submission_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('body');

        $this->post('/forum/topics/'.$topic->id.'/replies', [
            'body' => 'Check https://example.org',
            'submission_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('body');

        $this->assertDatabaseCount('module_forum_topics', 1);
        $this->assertSame(0, $topic->fresh()->reply_count);
        $this->assertSame(1, $topic->posts()->count());
    }

    public function test_revoked_moderator_cannot_use_a_cached_role_for_topic_mutation(): void
    {
        $writer = User::factory()->create();
        $moderator = User::factory()->create();
        $topic = $this->createTopic($writer, 'Moderator revocation test');

        DB::table('module_forum_moderators')->insert([
            'user_id' => $moderator->id,
            'assigned_by_admin_id' => null,
            'created_at' => now(),
        ]);
        $actor = new ForumActor('user', user: $moderator);
        $permissions = app(ForumPermissions::class);
        $moderation = new ForumTopicModeration(
            $permissions,
            app(ForumStructure::class),
            app(AuditLogger::class),
        );

        $this->assertTrue($permissions->isModerator($actor));
        DB::table('module_forum_moderators')->where('user_id', $moderator->id)->delete();
        // Reuse the *same* cached permissions instance to reproduce a revoked
        // moderator whose request began while their role was still active.
        try {
            $moderation->change($actor, $topic->id, 'hide');
            $this->fail('Revoked permissions must be rejected under the write lock.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertNull($topic->fresh()->hidden_at);
    }

    public function test_revoked_moderator_cannot_publish_using_cached_permissions(): void
    {
        $writer = User::factory()->create();
        $moderator = User::factory()->create();
        $topic = $this->createTopic($writer, 'Publication revocation test');
        $section = ForumSection::query()->findOrFail($topic->section_id);
        $section->update(['view_rule' => 'moderators', 'topic_rule' => 'moderators', 'reply_rule' => 'moderators']);
        DB::table('module_forum_moderators')->insert([
            'user_id' => $moderator->id,
            'assigned_by_admin_id' => null,
            'created_at' => now(),
        ]);
        $actor = new ForumActor('user', user: $moderator);
        $posting = app(ForumPosting::class);
        $this->assertTrue($posting->canStart($actor, $section));
        $this->assertTrue($posting->canReply($actor, $topic, $section));
        DB::table('module_forum_moderators')->where('user_id', $moderator->id)->delete();

        foreach ([
            fn () => $posting->createTopic($actor, $section->id, 'New topic', 'New message', (string) Str::uuid()),
            fn () => $posting->addReply($actor, $topic->id, 'New reply', (string) Str::uuid()),
        ] as $publish) {
            try {
                $publish();
                $this->fail('Revoked permissions must block publication.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }

        $this->assertDatabaseCount('module_forum_topics', 1);
        $this->assertDatabaseCount('module_forum_posts', 1);
    }

    public function test_moderator_cannot_restore_or_unhide_an_owner_suppressed_staff_reply(): void
    {
        $owner = Admin::factory()->create();
        $staff = Admin::factory()->administrator()->create();
        $moderator = User::factory()->create();
        $writer = User::factory()->create();
        $topic = $this->createTopic($writer, 'Staff protected thread');
        app(ForumPosting::class)->addReply(
            new ForumActor('admin', admin: $staff),
            $topic->id,
            'Official staff response',
            (string) Str::uuid(),
        );
        DB::table('module_forum_moderators')->insert([
            'user_id' => $moderator->id,
            'assigned_by_admin_id' => $owner->id,
            'created_at' => now(),
        ]);

        $this->actingAs($owner, 'admin')->withSession(['forum.author_mode' => 'admin']);
        $this->post('/forum/topics/'.$topic->id.'/moderate/hide')->assertRedirect();

        $this->actingAs($moderator, 'web')->withSession(['forum.author_mode' => 'user']);
        $this->post('/forum/topics/'.$topic->id.'/moderate/unhide')->assertSessionHasErrors('moderation');
        $this->assertNotNull($topic->fresh()->hidden_at);

        $this->actingAs($owner, 'admin')->withSession(['forum.author_mode' => 'admin']);
        $this->post('/forum/topics/'.$topic->id.'/moderate/delete')->assertRedirect();

        $this->actingAs($moderator, 'web')->withSession(['forum.author_mode' => 'user']);
        $this->post('/forum/topics/'.$topic->id.'/moderate/restore')->assertSessionHasErrors('moderation');
        $this->assertTrue(ForumTopic::withTrashed()->findOrFail($topic->id)->trashed());
    }

    public function test_visible_counters_and_latest_activity_ignore_hidden_or_deleted_topics(): void
    {
        $writer = User::factory()->create();
        $actor = new ForumActor('user', user: $writer);
        $section = $this->section('Общее общение');
        $first = $this->createTopic($writer, 'Older topic');
        $this->travel(5)->seconds();
        $second = $this->createTopic($writer, 'Newer topic');
        $moderation = app(ForumTopicModeration::class);
        $owner = new ForumActor('admin', admin: Admin::factory()->create());

        $visible = fn () => app(ForumStructure::class)
            ->visibleCategories($actor, app(ForumPermissions::class))
            ->flatMap(fn ($category) => $category->sections)
            ->firstWhere('id', $section->id);

        // The latest message shown for the section comes from visible topics only.
        $latestTopic = function () use ($actor, $section): ?int {
            $structure = app(ForumStructure::class);
            $categories = $structure->visibleCategories($actor, app(ForumPermissions::class));

            return $structure->sectionActivity($categories)[$section->id]['topic']?->id;
        };

        $this->assertSame(2, $visible()->topics_count);
        $this->assertSame($second->id, $latestTopic());

        $moderation->change($owner, $second->id, 'hide');
        $this->assertSame(1, $visible()->topics_count);
        $this->assertSame($first->id, $latestTopic());

        $moderation->change($owner, $second->id, 'delete');
        $this->assertSame(1, $visible()->topics_count);
        $moderation->change($owner, $second->id, 'restore');
        $this->assertSame(1, $visible()->topics_count);
        $moderation->change($owner, $second->id, 'unhide');
        $this->assertSame(2, $visible()->topics_count);
    }

    public function test_archiving_keeps_message_ids_and_moves_section_counts(): void
    {
        $writer = User::factory()->create();
        $owner = new ForumActor('admin', admin: Admin::factory()->create());
        $topic = $this->createTopic($writer, 'Topic with reply');
        $reply = app(ForumPosting::class)->addReply(
            new ForumActor('user', user: $writer),
            $topic->id, 'Follow-up message', (string) Str::uuid()
        );
        $source = $this->section('Общее общение');
        $archive = $this->section('Архив');

        $this->assertTrue(app(ForumTopicModeration::class)->move(
            $owner, $topic->id, (int) $archive->id, false
        ));

        $topic->refresh();
        $this->assertSame($archive->id, $topic->section_id);
        $this->assertSame(1, $topic->reply_count);
        $this->assertSame($topic->id, $reply->fresh()->topic_id);
        $this->assertSame(0, $source->topics()->count());
        $this->assertSame(1, $archive->topics()->count());

        $this->actingAs($writer, 'web')->withSession(['forum.author_mode' => 'user']);
        $this->get('/forum/topics/'.$topic->id)->assertOk()->assertSee('Follow-up message');
    }

    public function test_section_activity_uses_a_bounded_batch_for_populated_sections(): void
    {
        $actor = new ForumActor('user', user: User::factory()->create());
        $structure = app(ForumStructure::class);
        $sections = ForumSection::query()->where('topic_rule', 'registered')->where('view_rule', 'all')->get();
        $this->assertGreaterThan(1, $sections->count());
        foreach ($sections as $section) {
            app(ForumPosting::class)->createTopic($actor, $section->id, 'Section discussion', 'Message', (string) Str::uuid());
        }
        $categories = $structure->visibleCategories($actor, app(ForumPermissions::class));
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $activity = $structure->sectionActivity($categories);
            $this->assertLessThanOrEqual(6, count(DB::getQueryLog()));
        } finally {
            DB::disableQueryLog();
        }
        foreach ($sections as $section) {
            $this->assertSame($section->id, $activity[$section->id]['topic']->section_id);
            $this->assertSame(1, $activity[$section->id]['posts']);
        }
    }

    public function test_search_paginates_filtered_results_without_old_revision_text(): void
    {
        $writer = User::factory()->create();
        $actor = new ForumActor('user', user: $writer);
        $section = $this->section('Общее общение');
        for ($i = 0; $i < 22; $i++) {
            app(ForumPosting::class)->createTopic(
                $actor, $section->id,
                'Searchable '.$i, 'New search content', (string) Str::uuid()
            );
        }

        $this->get('/forum/search?q=Searchable')
            ->assertOk()->assertSee('Searchable 21')->assertDontSee('Searchable 0</a>', false);
        $this->get('/forum/search?q=Searchable&page=2')
            ->assertOk()->assertSee('Searchable 0</a>', false)->assertDontSee('Searchable 21</a>', false);
    }

    public function test_cached_search_follows_post_changes_and_counts_only_visible_posts(): void
    {
        $writer = User::factory()->create();
        $actor = new ForumActor('user', user: $writer);
        $topic = $this->createTopic($writer, 'Visible topic');
        $hidden = $this->createTopic($writer, 'Hidden topic');
        $hidden->update(['hidden_at' => now()]);
        $this->get('/forum/topics/'.$topic->id)->assertOk()
            ->assertViewHas('authors', static fn ($authors) => reset($authors)['posts'] === 1);
        $reply = app(ForumPosting::class)->addReply($actor, $topic->id, 'First phrase', (string) Str::uuid());
        $changes = app(ForumPostChanges::class);
        $this->get('/forum/search?q=First')->assertSee('Visible topic');
        $changes->update($actor, $reply->id, 'Second phrase', null, $reply->refresh()->edit_version);
        $this->get('/forum/search?q=First')->assertDontSee('Visible topic');
        $this->get('/forum/search?q=Second')->assertSee('Visible topic');
        $changes->remove($actor, $reply->id, $reply->fresh()->edit_version);
        $this->get('/forum/search?q=Second')->assertDontSee('Visible topic');
        $this->get('/forum/search?q=Visible&page=10')->assertNotFound();
        $this->get('/forum/topics/'.$topic->id.'?page=10')->assertOk()->assertDontSee('class="forum-pagination"', false);
    }

    private function createTopic(User $writer, string $title): ForumTopic
    {
        return app(ForumPosting::class)->createTopic(
            new ForumActor('user', user: $writer),
            $this->section('Общее общение')->id,
            $title,
            'Opening message',
            (string) Str::uuid()
        );
    }

    private function section(string $title): ForumSection
    {
        return ForumSection::query()->where('name_ru', $title)->firstOrFail();
    }
}
