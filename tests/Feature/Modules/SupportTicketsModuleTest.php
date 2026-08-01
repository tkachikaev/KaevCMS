<?php

namespace Tests\Feature\Modules;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Models\AdminNotification;
use App\Models\User;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleAdminComponent;
use App\Support\Modules\ModuleManager;
use App\Support\Modules\ModuleNavigationRegistry;
use App\Support\Modules\ModuleRuntime;
use App\Support\Notifications\AdminNotificationType;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use KaevCMS\Modules\SupportTickets\Enums\SupportTicketCategory;
use KaevCMS\Modules\SupportTickets\Enums\SupportTicketStatus;
use KaevCMS\Modules\SupportTickets\Livewire\AccountTicketConversation;
use KaevCMS\Modules\SupportTickets\Livewire\AccountTicketIndex;
use KaevCMS\Modules\SupportTickets\Livewire\AdminTicketConversation;
use KaevCMS\Modules\SupportTickets\Models\SupportTicket;
use KaevCMS\Modules\SupportTickets\Models\SupportTicketMessage;
use KaevCMS\Modules\SupportTickets\Models\SupportTicketMessageRevision;
use KaevCMS\Modules\SupportTickets\Models\SupportTicketSetting;
use KaevCMS\Modules\SupportTickets\Services\SupportTicketCleanupService;
use Livewire\Livewire;
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
        $this->assertSame(realpath(base_path('modules/support-tickets/assets/module.webp')), $module['image_path']);
        $this->assertTrue(Schema::hasTable('module_support_ticket_settings'));
        $this->assertTrue(Schema::hasTable('module_support_tickets'));
        $this->assertTrue(Schema::hasTable('module_support_ticket_messages'));
        $this->assertTrue(Schema::hasTable('module_support_ticket_message_revisions'));
        $this->assertTrue(Schema::hasColumn('module_support_ticket_settings', 'allow_editor_view'));
        $this->assertTrue(Schema::hasColumn('module_support_ticket_settings', 'retention_months'));
        $this->assertTrue(Schema::hasColumn('module_support_ticket_settings', 'max_tickets_per_day'));
        $this->assertTrue(Schema::hasColumn('module_support_ticket_settings', 'max_player_messages_per_day'));
        $this->assertTrue(Schema::hasColumn('module_support_ticket_settings', 'max_messages_per_ticket'));
        $this->assertTrue(Schema::hasColumn('module_support_ticket_settings', 'max_revisions_per_message'));
        $this->assertTrue(Schema::hasColumn('module_support_ticket_settings', 'max_open_tickets_per_user'));
        $this->assertTrue(Schema::hasColumn('module_support_ticket_settings', 'subject_max_length'));
        $this->assertTrue(Schema::hasColumn('module_support_ticket_settings', 'initial_message_max_length'));
        $this->assertTrue(Schema::hasColumn('module_support_ticket_settings', 'message_max_length'));
        $this->assertTrue(Schema::hasColumn('module_support_tickets', 'retention_protected'));
        $this->assertDatabaseHas('cms_modules', [
            'id' => 'support-tickets',
            'version' => '1.6.0',
            'enabled' => true,
        ]);

        $accountLinks = app(ModuleNavigationRegistry::class)->accountLinks();
        $adminLinks = app(ModuleNavigationRegistry::class)->adminLinks();
        $this->assertContains('modules.support-tickets.index', array_column($accountLinks, 'route'));
        $this->assertContains('admin.module-pages.support-tickets.index', array_column($adminLinks, 'route'));
        $this->assertTrue(app(ModuleAdminAccessRegistry::class)->isRegistered('admin.module-pages.support-tickets.index'));
        $this->assertTrue(app(ModuleAdminAccessRegistry::class)->isRegistered('admin.module-pages.support-tickets.settings'));
        $this->assertTrue(is_subclass_of(AdminTicketConversation::class, ModuleAdminComponent::class));
        $adminConversation = (string) file_get_contents(base_path('modules/support-tickets/resources/views/livewire/admin-ticket-conversation.blade.php'));
        $supportCss = (string) file_get_contents(public_path('assets/modules/support-tickets.css'));
        $this->assertStringContainsString('support-ticket-admin-heading-start', $adminConversation);
        $this->assertStringContainsString('support-ticket-admin-heading-center', $adminConversation);
        $this->assertStringContainsString('support-ticket-admin-heading-actions', $adminConversation);
        $this->assertStringContainsString('.support-chat-composer textarea', $supportCss);
        $this->assertStringContainsString('resize: vertical', $supportCss);
        $this->assertStringContainsString('min-width: 100%', $supportCss);
        $this->assertStringContainsString('max-width: 100%', $supportCss);
        $this->assertStringContainsString('border-radius: 12px', $supportCss);
        $this->assertStringContainsString('support-message-edit-icon', $adminConversation);
        $this->assertStringContainsString('aria-label="{{ __(\'module-support-tickets::messages.edit_message\') }}"', $adminConversation);
        $this->assertStringNotContainsString(">{{ __('module-support-tickets::messages.edit_message') }}</button>", $adminConversation);
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

    public function test_player_creates_ticket_with_default_category_and_length_limits(): void
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

    public function test_new_ticket_and_player_reply_notify_only_eligible_staff_without_personal_text(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $administrator = Admin::factory()->create(['role' => AdminRole::Administrator]);
        $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
        $inactive = Admin::factory()->inactive()->create(['role' => AdminRole::Owner]);
        $user = User::factory()->create([
            'name' => 'Private Player Name',
            'email' => 'private-player@example.test',
        ]);

        $this->actingAs($user)
            ->post('/modules/support-tickets', [
                'category' => SupportTicketCategory::TechnicalProblem->value,
                'subject' => 'Private subject that must not enter the notification',
                'body' => 'Private support message that must not enter the notification',
            ])
            ->assertRedirect();

        $ticket = SupportTicket::query()->firstOrFail();
        $created = AdminNotification::query()
            ->where('type', AdminNotificationType::SupportTicketCreated->value)
            ->get();

        $this->assertCount(3, $created);
        $this->assertEqualsCanonicalizing(
            [$owner->id, $administrator->id, $auditor->id],
            $created->pluck('admin_id')->all(),
        );
        $this->assertNotContains($editor->id, $created->pluck('admin_id')->all());
        $this->assertNotContains($inactive->id, $created->pluck('admin_id')->all());

        foreach ($created as $notification) {
            $this->assertSame(['number' => $ticket->number()], $notification->parameters);
            $this->assertSame(['ticket' => $ticket->id], $notification->route_parameters);
            $payload = json_encode($notification->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('Private subject', $payload);
            $this->assertStringNotContainsString('Private support message', $payload);
            $this->assertStringNotContainsString('private-player@example.test', $payload);
            $this->assertStringNotContainsString('Private Player Name', $payload);
        }

        $this->actingAs($user)
            ->post('/modules/support-tickets/'.$ticket->id.'/reply', [
                'body' => 'Another private reply that must not enter the notification',
            ])
            ->assertRedirect();

        $replies = AdminNotification::query()
            ->where('type', AdminNotificationType::SupportTicketPlayerReply->value)
            ->get();
        $this->assertCount(3, $replies);
        $this->assertEqualsCanonicalizing(
            [$owner->id, $administrator->id, $auditor->id],
            $replies->pluck('admin_id')->all(),
        );
        $this->assertSame(6, AdminNotification::query()->count());
        $this->assertStringNotContainsString(
            'Another private reply',
            json_encode($replies->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
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

    public function test_owner_and_administrator_manage_tickets_editor_permissions_are_separate_and_auditor_is_read_only(): void
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

        $this->updateSettings($owner, allowEditorView: true);

        $this->actingAs($editor, 'admin')
            ->get('/admin/extensions/support-tickets/'.$ticket->id)
            ->assertOk()
            ->assertDontSee(__('module-support-tickets::messages.reply_to_player'));
        $this->actingAs($editor, 'admin')
            ->post('/admin/extensions/support-tickets/'.$ticket->id.'/reply', ['body' => 'Ответ запрещён'])
            ->assertForbidden();
        $this->actingAs($editor, 'admin')
            ->post('/admin/extensions/support-tickets/'.$ticket->id.'/note', ['body' => 'Заметка запрещена'])
            ->assertForbidden();

        $this->updateSettings($owner, allowEditorView: true, allowEditorReply: true);
        $this->actingAs($editor, 'admin')
            ->post('/admin/extensions/support-tickets/'.$ticket->id.'/reply', ['body' => 'Ответ редактора'])
            ->assertRedirect();
        $this->assertDatabaseHas('module_support_ticket_messages', [
            'ticket_id' => $ticket->id,
            'admin_id' => $editor->id,
            'body' => 'Ответ редактора',
            'is_internal' => false,
        ]);
        $this->actingAs($editor, 'admin')
            ->post('/admin/extensions/support-tickets/'.$ticket->id.'/note', ['body' => 'Заметка пока запрещена'])
            ->assertForbidden();

        $this->updateSettings(
            $owner,
            allowEditorView: true,
            allowEditorReply: true,
            allowEditorInternalNotes: true,
        );
        $this->actingAs($editor, 'admin')
            ->post('/admin/extensions/support-tickets/'.$ticket->id.'/note', ['body' => 'Заметка редактора'])
            ->assertRedirect();
        $this->assertDatabaseHas('module_support_ticket_messages', [
            'ticket_id' => $ticket->id,
            'admin_id' => $editor->id,
            'body' => 'Заметка редактора',
            'is_internal' => true,
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

        for ($index = 1; $index < SupportTicket::MAX_REVISIONS_PER_MESSAGE; $index++) {
            SupportTicketMessageRevision::query()->create([
                'message_id' => $message->id,
                'editor_admin_id' => $first->id,
                'editor_name_snapshot' => $first->name,
                'previous_body' => 'Архивная версия '.$index,
                'edited_at' => now(),
            ]);
        }

        $this->actingAs($first, 'admin')
            ->from('/admin/extensions/support-tickets/'.$ticket->id)
            ->put('/admin/extensions/support-tickets/'.$ticket->id.'/messages/'.$message->id, [
                'body' => 'Правка сверх лимита истории',
            ])
            ->assertSessionHasErrors('body');
        $this->assertSame('Исправленный ответ', $message->fresh()->body);
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

    public function test_daily_and_per_ticket_limits_are_enforced(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticket($user, SupportTicketStatus::InProgress);

        for ($index = 1; $index < SupportTicket::MAX_PLAYER_MESSAGES_PER_DAY; $index++) {
            $ticket->messages()->create([
                'author_type' => SupportTicketMessage::AUTHOR_PLAYER,
                'user_id' => $user->id,
                'author_name_snapshot' => $user->name,
                'is_internal' => false,
                'body' => 'Дневное сообщение '.$index,
            ]);
        }

        $this->actingAs($user)
            ->from('/modules/support-tickets/'.$ticket->id)
            ->post('/modules/support-tickets/'.$ticket->id.'/reply', ['body' => 'Сообщение сверх суточного лимита'])
            ->assertSessionHasErrors('body');

        $anotherUser = User::factory()->create();
        for ($index = 0; $index < SupportTicket::MAX_TICKETS_PER_USER_PER_DAY; $index++) {
            $dailyTicket = $this->ticket($anotherUser, SupportTicketStatus::Closed, 'Закрытое обращение '.$index);
            $dailyTicket->forceFill(['created_at' => now()->subMinutes($index)])->save();
        }

        $this->actingAs($anotherUser)
            ->from('/modules/support-tickets')
            ->post('/modules/support-tickets', [
                'category' => SupportTicketCategory::Other->value,
                'subject' => 'Обращение сверх суточного лимита',
                'body' => 'Оно не должно создаться.',
            ])
            ->assertSessionHasErrors('subject');

        $staffUser = User::factory()->create();
        $fullTicket = $this->ticket($staffUser, SupportTicketStatus::InProgress);
        $admin = Admin::factory()->create(['role' => AdminRole::Administrator]);
        for ($index = 1; $index < SupportTicket::MAX_MESSAGES_PER_TICKET; $index++) {
            $fullTicket->messages()->create([
                'author_type' => SupportTicketMessage::AUTHOR_ADMIN,
                'admin_id' => $admin->id,
                'author_name_snapshot' => $admin->name,
                'admin_role_snapshot' => $admin->role->value,
                'is_internal' => false,
                'body' => 'Сообщение переполненного тикета '.$index,
            ]);
        }

        $this->actingAs($admin, 'admin')
            ->from('/admin/extensions/support-tickets/'.$fullTicket->id)
            ->post('/admin/extensions/support-tickets/'.$fullTicket->id.'/reply', ['body' => 'Сообщение 301'])
            ->assertSessionHasErrors('body');
    }

    public function test_livewire_conversation_loads_fifty_latest_messages_first(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create(['role' => AdminRole::Administrator]);
        $ticket = $this->ticket($user, SupportTicketStatus::InProgress);

        for ($index = 1; $index <= 55; $index++) {
            $ticket->messages()->create([
                'author_type' => SupportTicketMessage::AUTHOR_ADMIN,
                'admin_id' => $admin->id,
                'author_name_snapshot' => $admin->name,
                'admin_role_snapshot' => $admin->role->value,
                'is_internal' => false,
                'body' => sprintf('Сообщение %02d', $index),
            ]);
        }

        $this->actingAs($user)
            ->get('/modules/support-tickets/'.$ticket->id)
            ->assertOk()
            ->assertSee('Сообщение 55')
            ->assertDontSee('Начальное сообщение')
            ->assertSee(__('module-support-tickets::messages.show_previous_messages'));

        $this->actingAs($admin, 'admin')
            ->get('/admin/extensions/support-tickets/'.$ticket->id)
            ->assertOk()
            ->assertSee('Сообщение 55')
            ->assertDontSee('Начальное сообщение')
            ->assertSee(__('module-support-tickets::messages.show_previous_messages'));
    }

    public function test_cleanup_deletes_only_expired_unprotected_closed_tickets(): void
    {
        $user = User::factory()->create();
        $old = $this->ticket($user, SupportTicketStatus::Closed, 'Старое удаляемое');
        $protected = $this->ticket($user, SupportTicketStatus::Closed, 'Старое защищённое');
        $active = $this->ticket($user, SupportTicketStatus::InProgress, 'Активное');
        $recent = $this->ticket($user, SupportTicketStatus::Closed, 'Недавнее закрытое');

        $old->update(['closed_at' => now()->subMonths(25)]);
        $protected->update(['closed_at' => now()->subMonths(25), 'retention_protected' => true]);
        $recent->update(['closed_at' => now()->subMonths(2)]);

        $message = $old->messages()->firstOrFail();
        SupportTicketMessageRevision::query()->create([
            'message_id' => $message->id,
            'editor_name_snapshot' => 'Support',
            'previous_body' => 'Старая версия',
            'edited_at' => now()->subMonths(25),
        ]);

        $cleanup = app(SupportTicketCleanupService::class);
        $preview = $cleanup->preview(24);
        $this->assertSame(1, $preview['tickets']);
        $this->assertSame(1, $preview['messages']);
        $this->assertSame(1, $preview['revisions']);

        $result = $cleanup->cleanup(24, 1);
        $this->assertSame(1, $result['tickets']);
        $this->assertSame(1, $result['messages']);
        $this->assertSame(1, $result['revisions']);
        $this->assertDatabaseMissing('module_support_tickets', ['id' => $old->id]);
        $this->assertDatabaseHas('module_support_tickets', ['id' => $protected->id]);
        $this->assertDatabaseHas('module_support_tickets', ['id' => $active->id]);
        $this->assertDatabaseHas('module_support_tickets', ['id' => $recent->id]);
    }

    public function test_cleanup_rechecks_selected_tickets_before_delete_and_reports_only_actual_deletions(): void
    {
        $user = User::factory()->create();
        $reopened = $this->ticket($user, SupportTicketStatus::Closed, 'Переоткрыто во время очистки');
        $protected = $this->ticket($user, SupportTicketStatus::Closed, 'Защищено во время очистки');
        $reopened->update(['closed_at' => now()->subMonths(25)]);
        $protected->update(['closed_at' => now()->subMonths(25)]);

        $changed = false;
        DB::listen(function (QueryExecuted $query) use (&$changed, $reopened, $protected): void {
            if (
                $changed
                || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains(strtolower($query->sql), 'module_support_tickets')
            ) {
                return;
            }

            $changed = true;
            SupportTicket::query()->whereKey($reopened->id)->update([
                'status' => SupportTicketStatus::InProgress->value,
                'closed_at' => null,
            ]);
            SupportTicket::query()->whereKey($protected->id)->update([
                'retention_protected' => true,
            ]);
        });

        $result = app(SupportTicketCleanupService::class)->cleanup(24, 2);

        $this->assertTrue($changed);
        $this->assertSame(0, $result['tickets']);
        $this->assertSame(0, $result['messages']);
        $this->assertSame(0, $result['revisions']);
        $this->assertDatabaseHas('module_support_tickets', [
            'id' => $reopened->id,
            'status' => SupportTicketStatus::InProgress->value,
        ]);
        $this->assertDatabaseHas('module_support_tickets', [
            'id' => $protected->id,
            'retention_protected' => true,
        ]);
    }

    public function test_staff_can_protect_a_ticket_and_only_owner_can_run_cleanup_actions(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticket($user, SupportTicketStatus::Closed);
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $administrator = Admin::factory()->create(['role' => AdminRole::Administrator]);

        $this->actingAs($administrator, 'admin')
            ->patch('/admin/extensions/support-tickets/'.$ticket->id.'/retention-protection', ['protected' => true])
            ->assertRedirect();
        $this->assertTrue($ticket->fresh()->retention_protected);

        $this->actingAs($administrator, 'admin')
            ->post('/admin/extensions/support-tickets/settings/cleanup-preview')
            ->assertForbidden();

        $this->actingAs($owner, 'admin')
            ->post('/admin/extensions/support-tickets/settings/cleanup-preview')
            ->assertRedirect()
            ->assertSessionHas('support_cleanup_preview');
    }

    public function test_support_ticket_indexes_cover_lists_assignments_and_retention(): void
    {
        $indexNames = array_column(Schema::getIndexes('module_support_tickets'), 'name');

        $this->assertContains('support_tickets_user_last_id_index', $indexNames);
        $this->assertContains('support_tickets_last_id_index', $indexNames);
        $this->assertContains('support_tickets_assigned_last_id_index', $indexNames);
        $this->assertContains('support_tickets_retention_index', $indexNames);
        $this->assertContains('support_tickets_user_created_id_index', $indexNames);

        $messageIndexNames = array_column(Schema::getIndexes('module_support_ticket_messages'), 'name');
        $this->assertContains('support_messages_player_daily_index', $messageIndexNames);
    }

    public function test_editor_reply_and_note_permissions_cannot_remain_enabled_without_view_access(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);

        $this->actingAs($owner, 'admin')
            ->put('/admin/extensions/support-tickets/settings', [
                'allow_editor_view' => false,
                'allow_editor_reply' => true,
                'allow_editor_internal_notes' => true,
                'retention_months' => 24,
                'automatic_cleanup_enabled' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('module_support_ticket_settings', [
            'id' => 1,
            'allow_editor_view' => false,
            'allow_editor_reply' => false,
            'allow_editor_internal_notes' => false,
        ]);
    }

    public function test_cleanup_command_supports_dry_run_and_respects_disabled_automatic_cleanup(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $user = User::factory()->create();
        $ticket = $this->ticket($user, SupportTicketStatus::Closed, 'Старое обращение для команды');
        $ticket->update(['closed_at' => now()->subMonths(25)]);

        $this->artisan('kaevcms:support-tickets-cleanup', ['--dry-run' => true])
            ->expectsOutput('Dry run completed. No records were deleted.')
            ->assertSuccessful();
        $this->assertDatabaseHas('module_support_tickets', ['id' => $ticket->id]);

        $this->actingAs($owner, 'admin')
            ->put('/admin/extensions/support-tickets/settings', [
                'allow_editor_view' => false,
                'allow_editor_reply' => false,
                'allow_editor_internal_notes' => false,
                'retention_months' => 24,
                'automatic_cleanup_enabled' => false,
            ])
            ->assertRedirect();

        $this->artisan('kaevcms:support-tickets-cleanup', ['--scheduled' => true])
            ->expectsOutput('Automatic support ticket cleanup is disabled.')
            ->assertSuccessful();
        $this->assertDatabaseHas('module_support_tickets', ['id' => $ticket->id]);
    }

    public function test_livewire_account_page_opens_with_ticket_list_and_reveals_creation_form_on_demand(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        Livewire::test(AccountTicketIndex::class)
            ->assertSet('creating', false)
            ->assertSee('Мои обращения')
            ->assertDontSee('Кратко опишите проблему')
            ->call('openCreateForm')
            ->assertSet('creating', true)
            ->assertSee('Кратко опишите проблему')
            ->assertSeeHtml('data-testid="support-ticket-create-form"');
    }

    public function test_livewire_player_reply_preserves_the_route_rate_limit(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticket($user, SupportTicketStatus::AwaitingPlayer);
        $rateLimitKey = 'support-ticket-player-reply:'.$user->getAuthIdentifier();
        RateLimiter::clear($rateLimitKey);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            RateLimiter::hit($rateLimitKey, 60);
        }

        $this->actingAs($user);
        Livewire::test(AccountTicketConversation::class, ['ticketId' => $ticket->id])
            ->set('body', 'Сообщение сверх минутного лимита')
            ->call('reply')
            ->assertHasErrors('body');

        $this->assertDatabaseMissing('module_support_ticket_messages', [
            'ticket_id' => $ticket->id,
            'body' => 'Сообщение сверх минутного лимита',
        ]);
        RateLimiter::clear($rateLimitKey);
    }

    public function test_livewire_player_reply_updates_conversation_without_redirect_contract(): void
    {
        $user = User::factory()->create();
        $ticket = $this->ticket($user, SupportTicketStatus::AwaitingPlayer);

        $this->actingAs($user);
        Livewire::test(AccountTicketConversation::class, ['ticketId' => $ticket->id])
            ->set('body', 'Дополнительная информация от игрока')
            ->call('reply')
            ->assertSet('body', '')
            ->assertSee('Дополнительная информация от игрока')
            ->assertSee('В работе');

        $this->assertSame(SupportTicketStatus::InProgress, $ticket->fresh()->status);
    }

    public function test_livewire_admin_reply_uses_staff_status_and_internal_note_is_collapsed_by_default(): void
    {
        $user = User::factory()->create();
        $admin = Admin::factory()->create(['role' => AdminRole::Administrator]);
        $ticket = $this->ticket($user, SupportTicketStatus::InProgress);

        $this->actingAs($admin, 'admin');
        Livewire::test(AdminTicketConversation::class, [
            'ticketId' => $ticket->id,
            'adminPath' => 'admin',
        ])
            ->assertSet('noteOpen', false)
            ->assertDontSeeHtml('data-testid="internal-note-form"')
            ->assertSee('Ожидает вашего ответа')
            ->call('toggleNote')
            ->assertSet('noteOpen', true)
            ->assertSeeHtml('data-testid="internal-note-form"')
            ->set('body', 'Ответ сотрудника без перезагрузки страницы')
            ->call('reply')
            ->assertSet('body', '')
            ->assertSee('Ответ сотрудника без перезагрузки страницы')
            ->assertSee('Ожидает ответа игрока');

        $this->assertSame(SupportTicketStatus::AwaitingPlayer, $ticket->fresh()->status);
    }

    public function test_livewire_staff_actions_share_the_registered_module_authorization_rules(): void
    {
        $user = User::factory()->create();
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $administrator = Admin::factory()->create(['role' => AdminRole::Administrator]);
        $editor = Admin::factory()->create(['role' => AdminRole::Editor]);
        $auditor = Admin::factory()->create(['role' => AdminRole::Auditor]);

        foreach ([$owner, $administrator] as $staff) {
            $ticket = $this->ticket($user, SupportTicketStatus::New, 'Разрешённое действие '.$staff->id);
            $this->actingAs($staff, 'admin');
            Livewire::test(AdminTicketConversation::class, [
                'ticketId' => $ticket->id,
                'adminPath' => 'admin',
            ])->call('assignToMe');
            $this->assertSame($staff->id, $ticket->fresh()->assigned_admin_id);
        }

        $ticket = $this->ticket($user, SupportTicketStatus::InProgress, 'Матрица Livewire-доступа');
        $this->actingAs($auditor, 'admin');
        Livewire::test(AdminTicketConversation::class, [
            'ticketId' => $ticket->id,
            'adminPath' => 'admin',
        ])->set('body', 'Запрещённый ответ аудитора')->call('reply')->assertForbidden();

        $this->actingAs($editor, 'admin');
        Livewire::test(AdminTicketConversation::class, [
            'ticketId' => $ticket->id,
            'adminPath' => 'admin',
        ])->assertForbidden();

        SupportTicketSetting::query()->updateOrCreate(['id' => 1], [
            'allow_editor_management' => false,
            'allow_editor_view' => true,
            'allow_editor_reply' => false,
            'allow_editor_internal_notes' => false,
            'retention_months' => 24,
            'automatic_cleanup_enabled' => true,
        ]);

        $this->actingAs($editor, 'admin');
        Livewire::test(AdminTicketConversation::class, [
            'ticketId' => $ticket->id,
            'adminPath' => 'admin',
        ])->assertSee(__('module-support-tickets::messages.conversation'))
            ->set('body', 'Запрещённый ответ редактора')
            ->call('reply')
            ->assertForbidden();
        Livewire::test(AdminTicketConversation::class, [
            'ticketId' => $ticket->id,
            'adminPath' => 'admin',
        ])->call('toggleNote')->assertForbidden();

        SupportTicketSetting::query()->whereKey(1)->update([
            'allow_editor_reply' => true,
            'allow_editor_internal_notes' => true,
        ]);

        Livewire::test(AdminTicketConversation::class, [
            'ticketId' => $ticket->id,
            'adminPath' => 'admin',
        ])->set('body', 'Разрешённый ответ редактора')
            ->call('reply')
            ->assertSee('Разрешённый ответ редактора')
            ->call('toggleNote')
            ->set('noteBody', 'Разрешённая заметка редактора')
            ->call('addNote')
            ->assertSee('Разрешённая заметка редактора');

        auth('admin')->logout();
        $this->actingAs($user);
        Livewire::test(AdminTicketConversation::class, [
            'ticketId' => $ticket->id,
            'adminPath' => 'admin',
        ])->assertForbidden();

        auth()->logout();
        Livewire::test(AdminTicketConversation::class, [
            'ticketId' => $ticket->id,
            'adminPath' => 'admin',
        ])->assertForbidden();
    }

    public function test_support_settings_use_standard_admin_toggle_layout(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);

        $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/support-tickets/settings')
            ->assertOk()
            ->assertSee('settings-toggle-row', false)
            ->assertSee('switch-control', false)
            ->assertDontSee('class="toggle-row"', false)
            ->assertSee('Ограничения обращений')
            ->assertSee('name="max_tickets_per_day"', false)
            ->assertSee('name="message_max_length"', false)
            ->assertSee('Отсчёт начинается с даты закрытия.');
    }

    public function test_support_settings_separate_cleanup_into_its_own_tab(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);

        $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/support-tickets/settings')
            ->assertOk()
            ->assertSee('data-testid="support-settings-tabs"', false)
            ->assertSee('Основные настройки')
            ->assertSee('Очистка базы')
            ->assertSee('support-settings-actions', false)
            ->assertDontSee('data-testid="support-cleanup-panel"', false);

        $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/support-tickets/settings?tab=cleanup')
            ->assertOk()
            ->assertSee('data-testid="support-cleanup-panel"', false)
            ->assertSee('Очистка базы технической поддержки')
            ->assertDontSee('name="allow_editor_view"', false)
            ->assertDontSee('Сохранить настройки');

        $this->actingAs($owner, 'admin')
            ->post('/admin/extensions/support-tickets/settings/cleanup-preview')
            ->assertRedirect('/admin/extensions/support-tickets/settings?tab=cleanup');
    }

    public function test_support_admin_list_and_ticket_page_use_shared_compact_layouts(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $user = User::factory()->create();
        $ticket = $this->ticket($user);

        $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/support-tickets')
            ->assertOk()
            ->assertSee('admin-filter-bar support-ticket-filters', false)
            ->assertSee('data-testid="support-ticket-filters"', false)
            ->assertDontSee('<h2>Фильтры</h2>', false);

        $this->actingAs($owner, 'admin')
            ->get('/admin/extensions/support-tickets/'.$ticket->id)
            ->assertOk()
            ->assertSee('data-testid="support-admin-ticket-layout"', false)
            ->assertSee('support-admin-ticket-main', false)
            ->assertSee('support-admin-ticket-side', false)
            ->assertSee('support-ticket-detail-list', false);
    }

    public function test_owner_can_configure_support_limits_and_the_values_are_enforced(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);
        $user = User::factory()->create();

        $this->actingAs($owner, 'admin')
            ->put('/admin/extensions/support-tickets/settings', [
                'allow_editor_view' => false,
                'allow_editor_reply' => false,
                'allow_editor_internal_notes' => false,
                'retention_months' => 24,
                'automatic_cleanup_enabled' => true,
                'max_tickets_per_day' => 2,
                'max_player_messages_per_day' => 10,
                'max_messages_per_ticket' => 20,
                'max_revisions_per_message' => 2,
                'max_open_tickets_per_user' => 1,
                'subject_max_length' => 40,
                'initial_message_max_length' => 500,
                'message_max_length' => 200,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('module_support_ticket_settings', [
            'id' => 1,
            'max_tickets_per_day' => 2,
            'max_player_messages_per_day' => 10,
            'max_messages_per_ticket' => 20,
            'max_revisions_per_message' => 2,
            'max_open_tickets_per_user' => 1,
            'subject_max_length' => 40,
            'initial_message_max_length' => 500,
            'message_max_length' => 200,
        ]);

        $this->actingAs($user)
            ->post('/modules/support-tickets', [
                'category' => SupportTicketCategory::TechnicalProblem->value,
                'subject' => str_repeat('S', 41),
                'body' => str_repeat('B', 501),
            ])
            ->assertSessionHasErrors(['subject', 'body']);

        $this->actingAs($user)
            ->post('/modules/support-tickets', [
                'category' => SupportTicketCategory::TechnicalProblem->value,
                'subject' => 'Настраиваемое ограничение',
                'body' => 'Допустимое первое сообщение.',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post('/modules/support-tickets', [
                'category' => SupportTicketCategory::TechnicalProblem->value,
                'subject' => 'Второе открытое обращение',
                'body' => 'Это обращение должно быть отклонено.',
            ])
            ->assertSessionHasErrors('subject');
    }

    public function test_owner_cannot_save_support_limits_outside_safe_ranges(): void
    {
        $owner = Admin::factory()->create(['role' => AdminRole::Owner]);

        $this->actingAs($owner, 'admin')
            ->from('/admin/extensions/support-tickets/settings')
            ->put('/admin/extensions/support-tickets/settings', [
                'allow_editor_view' => false,
                'allow_editor_reply' => false,
                'allow_editor_internal_notes' => false,
                'retention_months' => 24,
                'automatic_cleanup_enabled' => true,
                'max_tickets_per_day' => 0,
                'max_player_messages_per_day' => 9,
                'max_messages_per_ticket' => 19,
                'max_revisions_per_message' => 0,
                'max_open_tickets_per_user' => 0,
                'subject_max_length' => 121,
                'initial_message_max_length' => 10001,
                'message_max_length' => 99,
            ])
            ->assertRedirect('/admin/extensions/support-tickets/settings')
            ->assertSessionHasErrors([
                'max_tickets_per_day',
                'max_player_messages_per_day',
                'max_messages_per_ticket',
                'max_revisions_per_message',
                'max_open_tickets_per_user',
                'subject_max_length',
                'initial_message_max_length',
                'message_max_length',
            ]);

        $this->assertDatabaseHas('module_support_ticket_settings', [
            'id' => 1,
            'max_tickets_per_day' => 10,
            'max_player_messages_per_day' => 100,
            'max_messages_per_ticket' => 300,
        ]);
    }

    private function updateSettings(
        Admin $owner,
        bool $allowEditorView = false,
        bool $allowEditorReply = false,
        bool $allowEditorInternalNotes = false,
    ): void {
        $this->actingAs($owner, 'admin')
            ->put('/admin/extensions/support-tickets/settings', [
                'allow_editor_view' => $allowEditorView,
                'allow_editor_reply' => $allowEditorReply,
                'allow_editor_internal_notes' => $allowEditorInternalNotes,
                'retention_months' => 24,
                'automatic_cleanup_enabled' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('module_support_ticket_settings', [
            'id' => 1,
            'allow_editor_view' => $allowEditorView,
            'allow_editor_reply' => $allowEditorReply,
            'allow_editor_internal_notes' => $allowEditorInternalNotes,
        ]);
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
