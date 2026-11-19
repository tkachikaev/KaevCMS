<?php

namespace Tests\Feature\Modules;

use App\Models\Admin;
use App\Models\User;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KaevCMS\Modules\Forum\Models\ForumCategory;
use KaevCMS\Modules\Forum\Models\ForumSection;
use KaevCMS\Modules\Forum\Services\ForumActor;
use KaevCMS\Modules\Forum\Services\ForumPermissions;
use Tests\TestCase;

final class ForumStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('forum');
        app(ModuleRuntime::class)->bootModule($module);
    }

    public function test_f3_installs_structure_schema_without_default_data(): void
    {
        foreach (['module_forum_settings', 'module_forum_categories', 'module_forum_sections', 'module_forum_moderators'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertSame('0.2.0', app(ModuleManager::class)->inspect('forum')['version']);
        $this->assertDatabaseHas('module_forum_settings', [
            'id' => 1, 'visibility' => 'all', 'links_after_days' => 0,
        ]);
        $this->assertDatabaseCount('module_forum_categories', 0);
        $this->assertDatabaseCount('module_forum_sections', 0);
        $this->get('/forum')->assertOk()->assertDontSee('data-forum-dialog-open', false);
    }

    public function test_owner_creates_exact_agreed_structure_once(): void
    {
        $owner = Admin::factory()->create();
        $this->actingAs($owner, 'admin')
            ->get('/forum')->assertOk()->assertSee('data-forum-dialog-open="forum-category-create"', false);

        $this->post('/forum/structure/seed')->assertRedirect('/forum');

        $this->assertSame(['Информация', 'Сообщество', 'Разное'],
            ForumCategory::query()->orderBy('position')->pluck('name_ru')->all());
        $this->assertSame(['Новости и объявления', 'Общее общение', 'Вопросы и помощь', 'Предложения и идеи', 'Архив'],
            ForumSection::query()->orderBy('category_id')->orderBy('position')->pluck('name_ru')->all());
        $this->assertDatabaseHas('module_forum_sections', [
            'name_ru' => 'Новости и объявления', 'view_rule' => 'all',
            'topic_rule' => 'owner', 'reply_rule' => 'nobody',
        ]);
        $this->assertDatabaseHas('module_forum_sections', [
            'name_ru' => 'Архив', 'view_rule' => 'all',
            'topic_rule' => 'nobody', 'reply_rule' => 'nobody',
        ]);
        $this->post('/forum/structure/seed')->assertRedirect('/forum');
        $this->assertDatabaseCount('module_forum_categories', 3);
        $this->assertDatabaseCount('module_forum_sections', 5);

        $this->get('/forum')->assertOk()->assertSee('Архив')->assertSee('Новости и объявления');
    }

    public function test_only_owner_can_mutate_structure_even_when_cms_administrator_is_logged_in(): void
    {
        $admin = Admin::factory()->administrator()->create();
        $player = User::factory()->create();
        $data = $this->names('Forbidden');

        $this->actingAs($admin, 'admin');
        $this->post('/forum/structure/seed')->assertForbidden();
        $this->post('/forum/categories', $data)->assertForbidden();
        $this->get('/forum/trash')->assertForbidden();

        $this->actingAs($player, 'web');
        $this->post('/forum/categories', $data)->assertForbidden();
        $this->post('/forum/structure/seed')->assertForbidden();
        $this->assertDatabaseCount('module_forum_categories', 0);

        // Stale administrative sessions cannot recover Owner privileges.
        $owner = Admin::factory()->create();
        $this->actingAs($owner, 'admin');
        $this->withSession(['admin_session_version' => $owner->session_version + 1]);
        $this->post('/forum/categories', $data)->assertForbidden();
    }

    public function test_owner_can_create_edit_order_and_reparent_sections(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
        $this->post('/forum/categories', $this->names('Alpha'))->assertRedirect('/forum');
        $this->post('/forum/categories', $this->names('Beta'))->assertRedirect('/forum');
        $first = ForumCategory::query()->where('name_ru', 'Alpha')->firstOrFail();
        $second = ForumCategory::query()->where('name_ru', 'Beta')->firstOrFail();

        $this->post('/forum/categories/'.$second->id.'/move', ['direction' => 'up'])->assertRedirect('/forum');
        $this->assertSame(['Beta', 'Alpha'], ForumCategory::query()->orderBy('position')->pluck('name_ru')->all());

        $this->put('/forum/categories/'.$first->id, $this->names('Alpha Updated'))->assertRedirect('/forum');

        $this->post('/forum/categories/'.$first->id.'/sections', $this->sectionData('One'))->assertRedirect('/forum');
        $this->post('/forum/categories/'.$first->id.'/sections', $this->sectionData('Two'))->assertRedirect('/forum');

        $one = ForumSection::query()->where('name_ru', 'One')->firstOrFail();
        $two = ForumSection::query()->where('name_ru', 'Two')->firstOrFail();

        $this->post('/forum/sections/'.$two->id.'/move', ['direction' => 'up'])->assertRedirect('/forum');
        $this->assertSame(['Two', 'One'],
            ForumSection::query()->where('category_id', $first->id)->orderBy('position')->pluck('name_ru')->all());

        $this->put('/forum/sections/'.$one->id, $this->sectionData('Moved') + ['category_id' => $second->id])
            ->assertRedirect('/forum');
        $this->assertDatabaseHas('module_forum_sections', [
            'id' => $one->id, 'category_id' => $second->id, 'name_ru' => 'Moved',
        ]);
    }

    public function test_invalid_section_permissions_fail_on_server(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
        $category = $this->makeCategory('Private');

        $this->post('/forum/categories/'.$category->id.'/sections', $this->sectionData('Wrong', 'moderators', 'registered', 'moderators'))
            ->assertSessionHasErrors('topic_rule');
        $this->post('/forum/categories/'.$category->id.'/sections', $this->sectionData('Wrong', 'moderators', 'moderators', 'registered'))
            ->assertSessionHasErrors('topic_rule');
        $this->post('/forum/categories/'.$category->id.'/sections', $this->sectionData('Wrong', 'invalid', 'owner', 'nobody'))
            ->assertSessionHasErrors('view_rule');

        $this->assertDatabaseCount('module_forum_sections', 0);

        $this->post('/forum/categories/'.$category->id.'/sections',
            $this->sectionData('Allowed', 'moderators', 'owner', 'nobody'))->assertRedirect();
        $section = ForumSection::query()->firstOrFail();

        $this->put('/forum/sections/'.$section->id,
            $this->sectionData('Broken', 'moderators', 'registered', 'nobody') + ['category_id' => $category->id])
            ->assertSessionHasErrors('topic_rule');
        $this->assertDatabaseHas('module_forum_sections', ['id' => $section->id, 'name_ru' => 'Allowed']);
    }

    public function test_all_48_permission_combinations_follow_read_write_subset_rule(): void
    {
        foreach (ForumPermissions::VIEWS as $view) {
            foreach (ForumPermissions::WRITES as $topic) {
                foreach (ForumPermissions::WRITES as $reply) {
                    $expected = $view !== 'moderators' || ($topic !== 'registered' && $reply !== 'registered');
                    $this->assertSame($expected, ForumPermissions::compatible($view, $topic, $reply),
                        implode('/', [$view, $topic, $reply]));
                }
            }
        }

        $owner = new ForumActor('admin', admin: Admin::factory()->create());
        $admin = new ForumActor('admin', admin: Admin::factory()->administrator()->create());
        $user = User::factory()->create();
        $player = new ForumActor('user', user: $user);
        $guest = new ForumActor('guest');
        $permissions = app(ForumPermissions::class);
        $this->assertFalse($permissions->canWrite($guest, 'registered'));
        $this->assertTrue($permissions->canWrite($player, 'registered'));
        $this->assertTrue($permissions->canWrite($admin, 'registered'));
        $this->assertFalse($permissions->canWrite($admin, 'moderators'));
        $this->assertTrue($permissions->canWrite($owner, 'moderators'));
        $this->assertFalse($permissions->canWrite($player, 'owner'));
        $this->assertFalse($permissions->canWrite($owner, 'nobody'));
        $this->assertFalse($permissions->visibleTo($guest, 'all', 'registered'));

        DB::table('module_forum_moderators')->insert([
            'user_id' => $user->id, 'created_at' => now(),
        ]);
        $this->assertTrue($permissions->canWrite($player, 'moderators'));
    }

    public function test_private_categories_are_hidden_from_lists_and_direct_links(): void
    {
        $owner = Admin::factory()->create();
        $this->actingAs($owner, 'admin');
        $category = $this->makeCategory('Secret Category');
        $this->post('/forum/categories/'.$category->id.'/sections',
            $this->sectionData('Secret Section', 'moderators', 'owner', 'owner'))->assertRedirect();
        $section = ForumSection::query()->firstOrFail();

        $this->get('/forum')->assertSee('Secret Category')->assertSee('Secret Section');
        $this->actingAs(User::factory()->create(), 'web');
        $this->withSession(['forum.author_mode' => 'user']);
        $this->get('/forum')->assertDontSee('Secret Category')->assertDontSee('Secret Section');
        $this->get('/forum/sections/'.$section->id)->assertNotFound();

        // Even a CMS Administrator does not silently become a forum moderator.
        $this->actingAs(Admin::factory()->administrator()->create(), 'admin');
        $this->withSession(['forum.author_mode' => 'admin']);
        $this->get('/forum')->assertDontSee('Secret Section');
        $this->get('/forum/sections/'.$section->id)->assertNotFound();
    }

    public function test_global_visibility_applies_to_lists_and_section_urls(): void
    {
        $owner = Admin::factory()->create();
        $this->actingAs($owner, 'admin');
        $category = $this->makeCategory('Members');
        $this->post('/forum/categories/'.$category->id.'/sections', $this->sectionData('Visible To Members'))->assertRedirect();
        $section = ForumSection::query()->firstOrFail();

        DB::table('module_forum_settings')->where('id', 1)->update(['visibility' => 'registered']);
        $this->get('/forum')->assertSee('Visible To Members');

        $this->withSession(['forum.author_mode' => 'user']);
        $this->get('/forum')->assertDontSee('Visible To Members');
        $this->get('/forum/sections/'.$section->id)->assertNotFound();

        $this->actingAs(User::factory()->create(), 'web');
        $this->get('/forum')->assertSee('Visible To Members');
        $this->get('/forum/sections/'.$section->id)->assertOk();
    }

    public function test_delete_is_reversible_and_category_with_sections_is_protected(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
        $category = $this->makeCategory('Container');
        $this->post('/forum/categories/'.$category->id.'/sections', $this->sectionData('Archived'))->assertRedirect();
        $section = ForumSection::query()->firstOrFail();

        $this->delete('/forum/categories/'.$category->id, ['confirm' => '1'])->assertSessionHasErrors('category');
        $this->delete('/forum/sections/'.$section->id, ['confirm' => '1'])->assertRedirect();
        $this->assertSoftDeleted('module_forum_sections', ['id' => $section->id]);
        $this->get('/forum/sections/'.$section->id)->assertNotFound();

        $this->delete('/forum/categories/'.$category->id, ['confirm' => '1'])->assertRedirect();
        $this->assertSoftDeleted('module_forum_categories', ['id' => $category->id]);
        $this->post('/forum/trash/sections/'.$section->id)->assertSessionHasErrors('category');
        $this->post('/forum/trash/categories/'.$category->id)->assertRedirect();
        $this->post('/forum/trash/sections/'.$section->id)->assertRedirect();
        $this->get('/forum/sections/'.$section->id)->assertOk();
    }

    /** @return array<string, string> */
    private function names(string $name): array
    {
        return ['name_ru' => $name, 'name_en' => $name.' EN',
            'description_ru' => 'Описание', 'description_en' => 'Description'];
    }

    /** @return array<string, string> */
    private function sectionData(
        string $name,
        string $view = 'all',
        string $topic = 'registered',
        string $reply = 'registered',
    ): array {
        return $this->names($name) + [
            'view_rule' => $view, 'topic_rule' => $topic, 'reply_rule' => $reply,
        ];
    }

    private function makeCategory(string $name): ForumCategory
    {
        $this->post('/forum/categories', $this->names($name))->assertRedirect();

        return ForumCategory::query()->where('name_ru', $name)->firstOrFail();
    }
}
