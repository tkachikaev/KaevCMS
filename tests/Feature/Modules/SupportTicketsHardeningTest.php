<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Livewire\Admin\ModuleNavigationBadge;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\RegistrationSettings;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleNavigationRegistry;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use KaevCMS\Modules\SupportTickets\Enums\SupportTicketCategory;
use KaevCMS\Modules\SupportTickets\Enums\SupportTicketStatus;
use KaevCMS\Modules\SupportTickets\Livewire\AccountTicketConversation;
use KaevCMS\Modules\SupportTickets\Livewire\AdminTicketConversation;
use KaevCMS\Modules\SupportTickets\Models\SupportTicket;
use KaevCMS\Modules\SupportTickets\Models\SupportTicketMessage;
use KaevCMS\Modules\SupportTickets\Models\SupportTicketSetting;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketAttentionCounter;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketService;
use Livewire\Livewire;
use Tests\TestCase;

final class SupportTicketsHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $modules = app(ModuleManager::class);
        $module = $modules->enable('support-tickets');
        app(ModuleRuntime::class)->bootModule($module);
        Cache::flush();
    }

    public function test_cleanup_fails_closed_when_settings_are_unavailable(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticket($user, SupportTicketStatus::Closed);
        $ticket->update(['closed_at' => now()->subMonths(25)]);
        SupportTicketSetting::query()->delete();

        $this->artisan('kaevcms:support-tickets-cleanup', ['--scheduled' => true])
            ->expectsOutput('Support ticket cleanup was skipped because its settings could not be read.')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseHas('module_support_tickets', ['id' => $ticket->id]);
    }

    public function test_cleanup_fails_closed_when_retention_value_is_invalid(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticket($user, SupportTicketStatus::Closed);
        $ticket->update(['closed_at' => now()->subMonths(25)]);
        SupportTicketSetting::query()->whereKey(1)->update(['retention_months' => 99]);

        $this->artisan('kaevcms:support-tickets-cleanup', ['--scheduled' => true])
            ->expectsOutput('Support ticket cleanup was skipped because its settings could not be read.')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseHas('module_support_tickets', ['id' => $ticket->id]);
    }

    public function test_cleanup_fails_closed_when_automatic_cleanup_value_is_invalid(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticket($user, SupportTicketStatus::Closed);
        $ticket->update(['closed_at' => now()->subMonths(25)]);
        SupportTicketSetting::query()->whereKey(1)->update(['automatic_cleanup_enabled' => 2]);

        $this->artisan('kaevcms:support-tickets-cleanup', ['--scheduled' => true])
            ->expectsOutput('Support ticket cleanup was skipped because its settings could not be read.')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseHas('module_support_tickets', ['id' => $ticket->id]);
    }

    public function test_lowering_write_limit_does_not_hide_existing_ticket_history(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticket($user, SupportTicketStatus::InProgress);
        SupportTicketSetting::query()->whereKey(1)->update(['max_messages_per_ticket' => 20]);

        for ($number = 2; $number <= 56; $number++) {
            $ticket->messages()->create([
                'author_type' => SupportTicketMessage::AUTHOR_PLAYER,
                'user_id' => $user->id,
                'author_name_snapshot' => $user->name,
                'is_internal' => false,
                'body' => 'Историческое сообщение '.$number,
            ]);
        }

        $this->actingAs($user);
        Livewire::test(AccountTicketConversation::class, ['ticketId' => $ticket->id])
            ->assertDontSee('Начальное сообщение')
            ->call('loadPrevious')
            ->assertSee('Начальное сообщение');
    }

    public function test_attention_badge_counts_only_tickets_requiring_staff_response(): void
    {
        $user = User::factory()->create();
        $new = $this->ticket($user, SupportTicketStatus::New, 'Новое обращение');
        $this->ticket($user, SupportTicketStatus::InProgress, 'Игрок ожидает ответа');
        $this->ticket($user, SupportTicketStatus::AwaitingPlayer, 'Ожидается игрок');
        $this->ticket($user, SupportTicketStatus::Closed, 'Закрытое обращение');
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $counter = app(SupportTicketAttentionCounter::class);

        $this->assertSame(2, $counter->countFor($owner));

        $links = app(ModuleNavigationRegistry::class)->availableAdminLinks(
            $owner,
            app(ModuleAdminAccessRegistry::class),
        );
        $supportLink = collect($links)->firstWhere('module_id', 'support-tickets');
        $this->assertIsArray($supportLink);
        $this->assertSame(2, $supportLink['badge']);

        app(SupportTicketService::class)->replyAsStaff($new, $owner, 'Ответ сотрудника');

        $this->assertSame(1, $counter->countFor($owner));
    }

    public function test_attention_badge_livewire_component_refreshes_after_staff_reply_event(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticket($user, SupportTicketStatus::New, 'Требует ответа');
        $this->ticket($user, SupportTicketStatus::New, 'Второе обращение');
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);

        $this->actingAs($owner, 'admin');
        $badge = Livewire::test(ModuleNavigationBadge::class, [
            'moduleId' => 'support-tickets',
            'initialCount' => 2,
        ])->assertSet('count', 2);

        Livewire::test(AdminTicketConversation::class, [
            'ticketId' => $ticket->id,
            'adminPath' => 'admin',
        ])
            ->set('body', 'Ответ сотрудника')
            ->call('reply')
            ->assertDispatched('module-admin-badge-refresh', moduleId: 'support-tickets');

        $badge
            ->call('refreshFromEvent', 'support-tickets')
            ->assertSet('count', 1);
    }

    public function test_attention_badge_is_visually_hidden_when_the_count_is_zero(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $this->actingAs($owner, 'admin');

        Livewire::test(ModuleNavigationBadge::class, [
            'moduleId' => 'support-tickets',
            'initialCount' => 0,
        ])
            ->assertSet('count', 0)
            ->assertSee('hidden', false);

        $layoutCss = (string) file_get_contents(public_path('assets/admin/css/layout.css'));
        $this->assertStringContainsString('.admin-menu-badge[hidden]', $layoutCss);
        $this->assertMatchesRegularExpression('/\.admin-menu-badge\[hidden\]\s*\{\s*display:\s*none;\s*\}/', $layoutCss);
    }

    public function test_attention_badge_does_not_change_the_navigation_link_accessible_name(): void
    {
        $user = User::factory()->create();
        $this->ticket($user, SupportTicketStatus::New, 'Requires staff response');
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);

        $response = $this->actingAs($owner, 'admin')->get('/admin');

        $response
            ->assertOk()
            ->assertSee('aria-label="'.__('module-support-tickets::messages.admin_navigation_label').'"', false)
            ->assertSee('data-module-admin-badge="support-tickets"', false)
            ->assertSee('role="status"', false)
            ->assertSee('aria-live="polite"', false);
    }

    public function test_attention_badge_respects_staff_access_and_caps_visual_value(): void
    {
        $user = User::factory()->create();
        for ($number = 1; $number <= 101; $number++) {
            $this->ticket($user, SupportTicketStatus::New, 'Обращение '.$number);
        }

        $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $counter = app(SupportTicketAttentionCounter::class);

        $this->assertSame(0, $counter->countFor($auditor));
        $this->assertSame(0, $counter->countFor($editor));
        $this->assertSame(101, $counter->countFor($owner));

        SupportTicketSetting::query()->whereKey(1)->update([
            'allow_editor_view' => true,
            'allow_editor_reply' => true,
        ]);
        $this->assertSame(101, app(SupportTicketAttentionCounter::class)->countFor($editor));

        $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/support-tickets')
            ->assertOk()
            ->assertSee('admin-menu-badge', false)
            ->assertSee('99+');
    }

    public function test_inactive_user_cannot_continue_using_an_open_livewire_ticket(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticket($user, SupportTicketStatus::AwaitingPlayer);
        $this->actingAs($user);
        $component = Livewire::test(AccountTicketConversation::class, ['ticketId' => $ticket->id])
            ->set('body', 'Ответ после блокировки');

        User::query()->whereKey($user->id)->update(['is_active' => false]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => false]);

        $component
            ->call('reply')
            ->assertForbidden();

        $this->assertDatabaseMissing('module_support_ticket_messages', [
            'ticket_id' => $ticket->id,
            'body' => 'Ответ после блокировки',
        ]);
    }

    public function test_revoked_email_verification_blocks_an_open_livewire_ticket(): void
    {
        app(RegistrationSettings::class)->update(enabled: true, emailVerificationRequired: true);
        $user = User::factory()->create();
        $ticket = $this->ticket($user, SupportTicketStatus::AwaitingPlayer);
        $this->actingAs($user);
        $component = Livewire::test(AccountTicketConversation::class, ['ticketId' => $ticket->id])
            ->set('body', 'Ответ после отзыва подтверждения');

        User::query()->whereKey($user->id)->update(['email_verified_at' => null]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email_verified_at' => null]);

        $component
            ->call('reply')
            ->assertForbidden();
    }

    public function test_repeated_assignment_closing_and_retention_protection_are_idempotent(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create(['role' => AdminRole::Administrator]);
        $ticket = $this->ticket($user, SupportTicketStatus::New);
        $service = app(SupportTicketService::class);

        $service->assignTo($ticket, $admin);
        $service->assignTo($ticket->fresh(), $admin);
        $this->assertSame(1, AuditLog::query()->where('action', 'support_ticket.assigned')->count());

        $service->closeByStaff($ticket->fresh(), $admin);
        $closedAt = $ticket->fresh()->closed_at?->toISOString();
        $service->closeByStaff($ticket->fresh(), $admin);
        $this->assertSame($closedAt, $ticket->fresh()->closed_at?->toISOString());
        $this->assertSame(1, AuditLog::query()->where('action', 'support_ticket.closed_by_staff')->count());

        $service->setRetentionProtected($ticket->fresh(), $admin, true);
        $service->setRetentionProtected($ticket->fresh(), $admin, true);
        $this->assertSame(1, AuditLog::query()->where('action', 'support_ticket.retention_protected')->count());
    }

    public function test_editor_cannot_change_retention_protection_even_with_reply_access(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
        $user = User::factory()->create();
        $ticket = $this->ticket($user, SupportTicketStatus::Closed);

        $this->actingAs($owner, 'admin')
            ->put('/admin/extensions/support-tickets/settings', [
                'allow_editor_view' => true,
                'allow_editor_reply' => true,
                'allow_editor_internal_notes' => false,
                'retention_months' => 24,
                'automatic_cleanup_enabled' => true,
            ])
            ->assertRedirect();

        $this->actingAs($editor, 'admin')
            ->patch('/admin/extensions/support-tickets/'.$ticket->id.'/retention-protection', ['protected' => true])
            ->assertForbidden();

        $this->assertFalse($ticket->fresh()->retention_protected);
    }

    public function test_livewire_ticket_close_uses_the_same_rate_limit_contract(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticket($user, SupportTicketStatus::InProgress);
        $rateLimitKey = 'support-ticket-player-close:'.$user->getAuthIdentifier();
        RateLimiter::clear($rateLimitKey);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            RateLimiter::hit($rateLimitKey, 60);
        }

        $this->actingAs($user);
        Livewire::test(AccountTicketConversation::class, ['ticketId' => $ticket->id])
            ->call('closeTicket')
            ->assertHasErrors('close');

        $this->assertSame(SupportTicketStatus::InProgress, $ticket->fresh()->status);
        RateLimiter::clear($rateLimitKey);
    }

    private function ticket(
        User $user,
        SupportTicketStatus $status,
        string $subject = 'Тестовое обращение',
    ): SupportTicket {
        $now = now();
        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id,
            'user_name_snapshot' => $user->name,
            'user_email_snapshot' => $user->email,
            'category' => SupportTicketCategory::TechnicalProblem,
            'status' => $status,
            'subject' => $subject,
            'last_message_at' => $now,
            'last_player_message_at' => $now,
            'closed_at' => $status === SupportTicketStatus::Closed ? $now : null,
        ]);
        $ticket->messages()->create([
            'author_type' => SupportTicketMessage::AUTHOR_PLAYER,
            'user_id' => $user->id,
            'author_name_snapshot' => $user->name,
            'is_internal' => false,
            'body' => 'Начальное сообщение',
        ]);

        return $ticket;
    }
}
