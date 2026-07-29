<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\User;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleNavigationRegistry;
use App\Support\Modules\ModuleRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use KaevCMS\Modules\SupportTickets\Enums\SupportTicketCategory;
use KaevCMS\Modules\SupportTickets\Enums\SupportTicketStatus;
use KaevCMS\Modules\SupportTickets\Models\SupportTicket;
use KaevCMS\Modules\SupportTickets\Models\SupportTicketMessage;
use Tests\TestCase;

class SupportTicketsModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $modules = app(ModuleManager::class);
        $module = $modules->enable('support-tickets');
        app(ModuleRuntime::class)->bootModule($module);
    }

    public function test_bundled_module_is_valid_migrates_and_registers_navigation_and_access(): void
    {
        $module = app(ModuleManager::class)->inspect('support-tickets');

        $this->assertTrue($module['valid'], implode(PHP_EOL, $module['errors']));
        $this->assertTrue($module['compatible'], implode(PHP_EOL, $module['errors']));
        $this->assertTrue($module['enabled']);
        $this->assertSame([], $module['pending_migrations']);
        $this->assertNull($module['image_path']);
        $this->assertTrue(Schema::hasTable('module_support_ticket_settings'));
        $this->assertTrue(Schema::hasTable('module_support_tickets'));
        $this->assertTrue(Schema::hasTable('module_support_ticket_messages'));
        $this->assertTrue(Schema::hasTable('module_support_ticket_message_revisions'));
        $this->assertDatabaseHas('cms_modules', [
            'id' => 'support-tickets',
            'version' => '1.0.0',
            'enabled' => true,
        ]);

        $accountLinks = app(ModuleNavigationRegistry::class)->accountLinks();
        $adminLinks = app(ModuleNavigationRegistry::class)->adminLinks();
        $this->assertContains('modules.support-tickets.index', array_column($accountLinks, 'route'));
        $this->assertContains('admin.module-pages.support-tickets.index', array_column($adminLinks, 'route'));
        $this->assertTrue(app(ModuleAdminAccessRegistry::class)->isRegistered('admin.module-pages.support-tickets.index'));
        $this->assertTrue(app(ModuleAdminAccessRegistry::class)->isRegistered('admin.module-pages.support-tickets.settings'));
    }

    public function test_public_categories_and_status_values_match_the_approved_contract(): void
    {
        $this->assertSame([
            'gameplay',
            'game_account',
            'technical_problem',
            'website_error',
            'donations_and_bonuses',
            'complaint',
            'other',
        ], array_column(SupportTicketCategory::cases(), 'value'));
        $this->assertSame([
            'new',
            'in_progress',
            'awaiting_player',
            'closed',
        ], array_column(SupportTicketStatus::cases(), 'value'));
    }

    public function test_player_creates_ticket_with_fixed_category_and_length_limits(): void
    {
        $user = User::factory()->create(['name' => 'Support Player']);

        $this->actingAs($user)
            ->post('/modules/support-tickets', [
                'category' => SupportTicketCategory::DonationsAndBonuses->value,
                'subject' => 'Бонус не был начислен',
                'body' => 'Платёж прошёл, но бонус не появился.',
            ])
            ->assertRedirect();

        $ticket = SupportTicket::query()->firstOrFail();
        $this->assertSame(SupportTicketStatus::New, $ticket->status);
        $this->assertSame(SupportTicketCategory::DonationsAndBonuses, $ticket->category);
        $this->assertDatabaseHas('module_support_ticket_messages', [
            'ticket_id' => $ticket->id,
            'author_type' => SupportTicketMessage::AUTHOR_PLAYER,
            'is_internal' => false,
        ]);

        $this->actingAs($user)
            ->from('/modules/support-tickets')
            ->post('/modules/support-tickets', [
                'category' => SupportTicketCategory::Other->value,
                'subject' => str_repeat('S', SupportTicket::SUBJECT_MAX + 1),
                'body' => str_repeat('B', SupportTicket::INITIAL_MESSAGE_MAX + 1),
            ])
            ->assertRedirect('/modules/support-tickets')
            ->assertSessionHasErrors(['subject', 'body']);

        $this->actingAs($user)
            ->from('/modules/support-tickets')
            ->post('/modules/support-tickets', [
                'category' => 'custom_untrusted_category',
                'subject' => 'Некорректная категория',
                'body' => 'Категория должна быть выбрана только из списка модуля.',
            ])
            ->assertRedirect('/modules/support-tickets')
            ->assertSessionHasErrors('category');
    }

    public function test_player_and_staff_message_limits_are_enforced_server_side(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create(['role' => AdminRole::Administrator]);
        $ticket = $this->ticket($user, SupportTicketStatus::InProgress);
        $tooLong = str_repeat('M', SupportTicket::MESSAGE_MAX + 1);

        $this->actingAs($user)
            ->from('/modules/support-tickets/'.$ticket->id)
            ->post('/modules/support-tickets/'.$ticket->id.'/reply', ['body' => $tooLong])
            ->assertRedirect('/modules/support-tickets/'.$ticket->id)
            ->assertSessionHasErrors('body');

        $this->actingAs($admin, 'admin')
            ->from('/admin/extensions/support-tickets/'.$ticket->id)
            ->post('/admin/extensions/support-tickets/'.$ticket->id.'/reply', ['body' => $tooLong])
            ->assertRedirect('/admin/extensions/support-tickets/'.$ticket->id)
            ->assertSessionHasErrors('body');

        $this->actingAs($admin, 'admin')
            ->from('/admin/extensions/support-tickets/'.$ticket->id)
            ->post('/admin/extensions/support-tickets/'.$ticket->id.'/note', ['body' => $tooLong])
            ->assertRedirect('/admin/extensions/support-tickets/'.$ticket->id)
            ->assertSessionHasErrors('body');

        $staffMessage = $ticket->messages()->create([
            'author_type' => SupportTicketMessage::AUTHOR_ADMIN,
            'admin_id' => $admin->id,
            'author_name_snapshot' => $admin->name,
            'admin_role_snapshot' => $admin->role->value,
            'is_internal' => false,
            'body' => 'Короткий ответ',
        ]);
        $this->actingAs($admin, 'admin')
            ->from('/admin/extensions/support-tickets/'.$ticket->id)
            ->put('/admin/extensions/support-tickets/'.$ticket->id.'/messages/'.$staffMessage->id, ['body' => $tooLong])
            ->assertRedirect('/admin/extensions/support-tickets/'.$ticket->id)
            ->assertSessionHasErrors('body');
        $this->assertSame('Короткий ответ', $staffMessage->fresh()->body);
    }

    public function test_player_sees_only_own_ticket_and_never_sees_internal_notes(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $admin = Admin::factory()->create(['role' => AdminRole::Owner]);
        $ticket = $this->ticket($owner);
        $ticket->messages()->create([
            'author_type' => SupportTicketMessage::AUTHOR_ADMIN,
            'admin_id' => $admin->id,
            'author_name_snapshot' => $admin->name,
            'admin_role_snapshot' => $admin->role->value,
            'is_internal' => true,
            'body' => 'Скрытая служебная заметка',
        ]);

        $this->actingAs($owner)
            ->get('/modules/support-tickets/'.$ticket->id)
            ->assertOk()
            ->assertSee($ticket->subject)
            ->assertDontSee('Скрытая служебная заметка');

        $this->actingAs($other)
            ->get('/modules/support-tickets/'.$ticket->id)
            ->assertNotFound();
    }

    public function test_player_and_staff_replies_apply_the_approved_status_transitions(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create(['role' => AdminRole::Administrator]);
        $ticket = $this->ticket($user, SupportTicketStatus::New);

        $this->actingAs($admin, 'admin')
            ->post('/admin/extensions/support-tickets/'.$ticket->id.'/reply', ['body' => 'Уточните имя персонажа.'])
            ->assertRedirect();
        $this->assertSame(SupportTicketStatus::AwaitingPlayer, $ticket->fresh()->status);

        $this->actingAs($user)
            ->post('/modules/support-tickets/'.$ticket->id.'/reply', ['body' => 'Персонажа зовут Kaev.'])
            ->assertRedirect();
        $this->assertSame(SupportTicketStatus::InProgress, $ticket->fresh()->status);
    }

    public function test_closed_ticket_cannot_be_reopened_or_replied_to_by_player_but_staff_can_reopen_it(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create(['role' => AdminRole::Administrator]);
        $ticket = $this->ticket($user, SupportTicketStatus::InProgress);

        $this->actingAs($user)
            ->patch('/modules/support-tickets/'.$ticket->id.'/close')
            ->assertRedirect();
        $this->assertSame(SupportTicketStatus::Closed, $ticket->fresh()->status);

        $this->actingAs($user)
            ->from('/modules/support-tickets/'.$ticket->id)
            ->post('/modules/support-tickets/'.$ticket->id.'/reply', ['body' => 'Попытка открыть повторно'])
            ->assertRedirect('/modules/support-tickets/'.$ticket->id)
            ->assertSessionHasErrors('body');
        $this->assertSame(SupportTicketStatus::Closed, $ticket->fresh()->status);

        $this->actingAs($admin, 'admin')
            ->from('/admin/extensions/support-tickets/'.$ticket->id)
            ->post('/admin/extensions/support-tickets/'.$ticket->id.'/reply', ['body' => 'Ответ в закрытый тикет'])
            ->assertRedirect('/admin/extensions/support-tickets/'.$ticket->id)
            ->assertSessionHasErrors('body');
        $this->assertDatabaseMissing('module_support_ticket_messages', [
            'ticket_id' => $ticket->id,
            'body' => 'Ответ в закрытый тикет',
        ]);

        $this->actingAs($admin, 'admin')
            ->patch('/admin/extensions/support-tickets/'.$ticket->id.'/reopen')
            ->assertRedirect();
        $this->assertSame(SupportTicketStatus::InProgress, $ticket->fresh()->status);
    }

    public function test_staff_cannot_use_reopen_action_to_change_an_already_open_ticket_status(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create(['role' => AdminRole::Administrator]);
        $ticket = $this->ticket($user, SupportTicketStatus::AwaitingPlayer);

        $this->actingAs($admin, 'admin')
            ->from('/admin/extensions/support-tickets/'.$ticket->id)
            ->patch('/admin/extensions/support-tickets/'.$ticket->id.'/reopen')
            ->assertRedirect('/admin/extensions/support-tickets/'.$ticket->id)
            ->assertSessionHasErrors('status');

        $this->assertSame(SupportTicketStatus::AwaitingPlayer, $ticket->fresh()->status);
    }

    public function test_owner_and_administrator_manage_tickets_editor_is_optional_and_auditor_is_read_only(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticket($user);
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $administrator = Admin::factory()->create(['role' => AdminRole::Administrator]);
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
        $auditor = Admin::factory()->auditor()->create();

        foreach ([$owner, $administrator] as $staff) {
            $this->actingAs($staff, 'admin')
                ->get('/admin/extensions/support-tickets/'.$ticket->id)
                ->assertOk();
        }

        $this->actingAs($editor, 'admin')
            ->get('/admin/extensions/support-tickets')
            ->assertForbidden();

        $this->actingAs($auditor, 'admin')
            ->get('/admin/extensions/support-tickets/'.$ticket->id)
            ->assertOk()
            ->assertSee(__('Read-only mode'));
        $this->actingAs($auditor, 'admin')
            ->post('/admin/extensions/support-tickets/'.$ticket->id.'/reply', ['body' => 'Нельзя'])
            ->assertForbidden();

        $this->actingAs($owner, 'admin')
            ->put('/admin/extensions/support-tickets/settings', ['allow_editor_management' => '1'])
            ->assertRedirect();

        $this->actingAs($editor, 'admin')
            ->get('/admin/extensions/support-tickets/'.$ticket->id)
            ->assertOk();
        $this->actingAs($editor, 'admin')
            ->post('/admin/extensions/support-tickets/'.$ticket->id.'/reply', ['body' => 'Ответ редактора'])
            ->assertRedirect();
        $this->assertDatabaseHas('module_support_ticket_messages', [
            'ticket_id' => $ticket->id,
            'admin_id' => $editor->id,
            'body' => 'Ответ редактора',
        ]);

        $this->actingAs($editor, 'admin')
            ->get('/admin/extensions/support-tickets/settings')
            ->assertForbidden();

        $this->actingAs($administrator, 'admin')
            ->get('/admin/extensions/support-tickets/settings')
            ->assertForbidden();
    }

    public function test_staff_can_edit_only_own_messages_and_revision_history_is_preserved(): void
    {
        $user = User::factory()->create();
        $first = Admin::factory()->create(['role' => AdminRole::Administrator]);
        $second = Admin::factory()->create(['role' => AdminRole::Administrator]);
        $ticket = $this->ticket($user);
        $message = $ticket->messages()->create([
            'author_type' => SupportTicketMessage::AUTHOR_ADMIN,
            'admin_id' => $first->id,
            'author_name_snapshot' => $first->name,
            'admin_role_snapshot' => $first->role->value,
            'is_internal' => false,
            'body' => 'Ответ с опечаткой',
        ]);

        $this->actingAs($second, 'admin')
            ->put('/admin/extensions/support-tickets/'.$ticket->id.'/messages/'.$message->id, ['body' => 'Чужая правка'])
            ->assertForbidden();

        $playerMessage = $ticket->messages()->where('author_type', SupportTicketMessage::AUTHOR_PLAYER)->firstOrFail();
        $this->actingAs($first, 'admin')
            ->put('/admin/extensions/support-tickets/'.$ticket->id.'/messages/'.$playerMessage->id, ['body' => 'Правка сообщения игрока'])
            ->assertForbidden();
        $this->assertSame('Начальное сообщение', $playerMessage->fresh()->body);

        $this->actingAs($first, 'admin')
            ->put('/admin/extensions/support-tickets/'.$ticket->id.'/messages/'.$message->id, ['body' => 'Исправленный ответ'])
            ->assertRedirect();

        $this->assertDatabaseHas('module_support_ticket_messages', [
            'id' => $message->id,
            'body' => 'Исправленный ответ',
        ]);
        $this->assertDatabaseHas('module_support_ticket_message_revisions', [
            'message_id' => $message->id,
            'editor_admin_id' => $first->id,
            'previous_body' => 'Ответ с опечаткой',
        ]);


        $this->actingAs($user)
            ->get('/modules/support-tickets/'.$ticket->id)
            ->assertOk()
            ->assertSee('Исправленный ответ')
            ->assertSee(__('module-support-tickets::messages.edited_at', [
                'date' => $message->fresh()->edited_at?->format('d.m.Y H:i'),
            ]))
            ->assertDontSee('Ответ с опечаткой');
    }

    public function test_duplicate_messages_and_open_ticket_flood_are_rejected(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticket($user, SupportTicketStatus::InProgress);
        $ticket->messages()->create([
            'author_type' => SupportTicketMessage::AUTHOR_PLAYER,
            'user_id' => $user->id,
            'author_name_snapshot' => $user->name,
            'is_internal' => false,
            'body' => 'Одинаковый ответ',
        ]);

        $this->actingAs($user)
            ->from('/modules/support-tickets/'.$ticket->id)
            ->post('/modules/support-tickets/'.$ticket->id.'/reply', ['body' => 'Одинаковый ответ'])
            ->assertSessionHasErrors('body');

        for ($index = 1; $index < SupportTicket::MAX_OPEN_TICKETS_PER_USER; $index++) {
            $this->ticket($user, SupportTicketStatus::New, 'Обращение '.$index);
        }

        $this->actingAs($user)
            ->from('/modules/support-tickets')
            ->post('/modules/support-tickets', [
                'category' => SupportTicketCategory::Other->value,
                'subject' => 'Лишнее обращение',
                'body' => 'Оно должно быть отклонено.',
            ])
            ->assertSessionHasErrors('subject');
    }

    private function ticket(
        User $user,
        SupportTicketStatus $status = SupportTicketStatus::New,
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
