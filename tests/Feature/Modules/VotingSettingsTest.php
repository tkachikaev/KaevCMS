<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleNavigationRegistry;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class VotingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/extensions/voting/providers/ktreon';

    private const PROJECT = '11111111-2222-4333-8444-555555555555';

    private const OTHER_PROJECT = '22222222-3333-4444-8555-666666666666';

    protected function setUp(): void
    {
        parent::setUp();
        $module = app(ModuleManager::class)->enable('voting');
        app(ModuleRuntime::class)->bootModule($module);
        Http::preventStrayRequests();
    }

    public function test_install_is_disabled_and_does_not_expose_player_voting(): void
    {
        $this->assertDatabaseHas('module_voting_settings', [
            'provider_code' => 'ktreon', 'enabled' => false, 'pending_policy' => 'wait',
        ]);
        $this->assertContains('admin.module-pages.voting.index', array_column(app(ModuleNavigationRegistry::class)->adminLinks(), 'route'));
        $this->assertNotContains('modules.voting.index', array_column(app(ModuleNavigationRegistry::class)->accountLinks(), 'route'));
    }

    public function test_settings_encrypt_key_preserve_blank_and_clear_key_on_project_change(): void
    {
        $key = 'kti_'.str_repeat('a', 43);
        $owner = Admin::factory()->create();
        $this->actingAs($owner, 'admin')->put(self::URL, [
            'enabled' => 1, 'project_id' => self::PROJECT, 'password' => $key, 'pending_policy' => 'wait',
        ])->assertRedirect();
        $ciphertext = DB::table('module_voting_settings')->value('api_key_ciphertext');
        $this->assertIsString($ciphertext);
        $this->assertStringNotContainsString($key, $ciphertext);
        $this->assertSame($key, Crypt::decryptString($ciphertext));
        $this->get(self::URL)->assertOk()->assertDontSee($key);
        $this->assertDatabaseHas('audit_logs', ['action' => 'voting.settings_updated', 'actor_id' => $owner->id]);
        $this->put(self::URL, ['enabled' => 1, 'project_id' => self::PROJECT, 'password' => '', 'pending_policy' => 'immediate'])->assertRedirect();
        $this->assertSame($ciphertext, DB::table('module_voting_settings')->value('api_key_ciphertext'));
        $this->put(self::URL, ['enabled' => 1, 'project_id' => self::OTHER_PROJECT, 'pending_policy' => 'wait'])->assertSessionHasErrors('password');
        $this->assertDatabaseHas('module_voting_settings', ['project_id' => self::PROJECT, 'enabled' => true]);
        $this->put(self::URL, ['enabled' => 0, 'project_id' => self::OTHER_PROJECT, 'pending_policy' => 'wait'])->assertRedirect();
        $this->assertDatabaseHas('module_voting_settings', ['project_id' => self::OTHER_PROJECT, 'api_key_ciphertext' => null, 'enabled' => false]);
    }

    public function test_invalid_key_is_not_flashed_to_session(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin')->put(self::URL, [
            'enabled' => 1, 'project_id' => self::PROJECT, 'password' => 'invalid-private-key', 'pending_policy' => 'wait',
        ])->assertSessionHasErrors('password');
        $this->assertNull(session()->getOldInput('password'));
        $this->assertDatabaseHas('module_voting_settings', ['enabled' => false, 'api_key_ciphertext' => null]);
    }

    public function test_auditor_can_view_but_not_write_or_check_and_editor_is_denied(): void
    {
        $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
        $this->actingAs($auditor, 'admin')->get(self::URL)->assertOk();
        $this->put(self::URL, [])->assertForbidden();
        $this->post(self::URL.'/check')->assertForbidden();
        $this->actingAs($editor, 'admin')->get(self::URL)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_check_reads_only_one_event_and_records_safe_result(): void
    {
        $key = 'kti_'.str_repeat('b', 43);
        $this->actingAs(Admin::factory()->create(), 'admin')->put(self::URL, [
            'enabled' => 0, 'project_id' => self::PROJECT, 'password' => $key, 'pending_policy' => 'wait',
        ])->assertRedirect();
        $endpoint = 'https://ktreon.com/api/v1/projects/'.self::PROJECT.'/vote-events';
        Http::fake([$endpoint.'*' => Http::response(['events' => [], 'next_cursor' => '0', 'has_more' => false])]);
        $this->post(self::URL.'/check')->assertRedirect()->assertSessionHas('status');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === $endpoint.'?cursor=0&limit=1'
            && $request->hasHeader('Authorization', 'Bearer '.$key));
        $this->assertDatabaseHas('module_voting_settings', ['last_check_result' => 'ok', 'enabled' => false]);
        $this->get(self::URL)->assertOk()->assertDontSee($key);
    }

    public function test_check_without_credentials_does_not_send_a_request(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin')->post(self::URL.'/check')
            ->assertRedirect()->assertSessionHasErrors('connection');
        $this->assertDatabaseHas('module_voting_settings', ['last_check_result' => 'not_configured']);
        Http::assertNothingSent();
    }

    public function test_redirect_is_not_a_successful_connection(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin')->put(self::URL, [
            'enabled' => 0, 'project_id' => self::PROJECT, 'password' => 'kti_'.str_repeat('e', 43), 'pending_policy' => 'wait',
        ])->assertRedirect();
        $endpoint = 'https://ktreon.com/api/v1/projects/'.self::PROJECT.'/vote-events';
        Http::fake([$endpoint.'*' => Http::response('', 302, ['Location' => 'https://example.invalid/'])]);
        $this->post(self::URL.'/check')->assertRedirect()->assertSessionHasErrors('connection');
        Http::assertSentCount(1);
        $this->assertDatabaseHas('module_voting_settings', ['last_check_result' => 'unavailable']);
    }

    public function test_check_rejects_bad_response_and_credential_rotation_invalidates_old_result(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin')->put(self::URL, [
            'enabled' => 0, 'project_id' => self::PROJECT, 'password' => 'kti_'.str_repeat('c', 43), 'pending_policy' => 'wait',
        ])->assertRedirect();
        $endpoint = 'https://ktreon.com/api/v1/projects/'.self::PROJECT.'/vote-events';
        Http::fake([$endpoint.'*' => Http::response(['events' => [], 'next_cursor' => '999', 'has_more' => false])]);
        $this->post(self::URL.'/check')->assertRedirect()->assertSessionHasErrors('connection');
        $this->assertDatabaseHas('module_voting_settings', ['last_check_result' => 'invalid_response']);
        $this->put(self::URL, [
            'enabled' => 0, 'project_id' => self::PROJECT, 'password' => 'kti_'.str_repeat('d', 43), 'pending_policy' => 'wait',
        ])->assertRedirect();
        $this->assertDatabaseHas('module_voting_settings', ['last_check_result' => null, 'last_checked_at' => null]);
    }
}
