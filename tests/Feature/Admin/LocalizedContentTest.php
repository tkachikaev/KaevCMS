<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\GameServerManager;
use App\Models\Admin;
use App\Models\GameServer;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class LocalizedContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_general_site_text_can_be_saved_in_russian_and_english(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->put('/admin/settings', [
                'translations' => [
                    'ru' => [
                        'name' => 'Мой сервер',
                        'description' => 'Русское описание сервера',
                        'footer_text' => 'Русский подвал',
                    ],
                    'en' => [
                        'name' => 'My Server',
                        'description' => 'English server description',
                        'footer_text' => 'English footer',
                    ],
                ],
                'timezone' => 'UTC',
                'admin_email' => 'admin@example.com',
                'remove_logo' => '0',
                'remove_favicon' => '0',
            ])
            ->assertRedirect(route('admin.settings.general'));

        $this->assertDatabaseHas('cms_settings', ['key' => 'site.name.ru', 'value' => 'Мой сервер']);
        $this->assertDatabaseHas('cms_settings', ['key' => 'site.name.en', 'value' => 'My Server']);
        $this->assertDatabaseHas('cms_settings', ['key' => 'site.description.en', 'value' => 'English server description']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Мой сервер')
            ->assertSee('Русское описание сервера');

        $this->get('/en')
            ->assertOk()
            ->assertSee('My Server')
            ->assertSee('English server description')
            ->assertSee('English footer');
    }

    public function test_game_server_name_can_be_translated(): void
    {
        $admin = $this->createAdmin();
        $server = GameServer::query()->firstOrFail();

        $this->actingAs($admin, 'admin');

        Livewire::test(GameServerManager::class)
            ->call('edit', $server->id)
            ->set('translations.ru', 'Основной сервер')
            ->set('translations.en', 'Main Server')
            ->set('serverRates', 'x5')
            ->set('serverChronicle', 'High Five')
            ->set('serverMode', 'PvP')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('game_server_translations', [
            'game_server_id' => $server->id,
            'locale' => 'ru',
            'name' => 'Основной сервер',
        ]);
        $this->assertDatabaseHas('game_server_translations', [
            'game_server_id' => $server->id,
            'locale' => 'en',
            'name' => 'Main Server',
        ]);

        $this->get('/')->assertSee('Основной сервер');
        $this->get('/en')->assertSee('Main Server');
    }

    public function test_english_mail_template_is_used_for_english_user(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->put('/admin/settings/mail/templates/email_verification', [
                'locale' => 'en',
                'subject' => 'Verify {{site_name}} account for {{username}}',
                'header' => '{{site_name}}',
                'heading' => 'Verify your email',
                'body' => 'Hello, {{username}}!',
                'action_text' => 'Verify email',
                'footer' => 'The link is valid for {{expires_in}}.',
            ])
            ->assertRedirect(route('admin.settings.mail.template', [
                'template' => 'email_verification',
                'locale' => 'en',
            ]));

        $user = User::query()->create([
            'name' => 'english_player',
            'email' => 'english@example.com',
            'password' => Hash::make('Password123'),
            'is_active' => true,
            'locale' => 'en',
        ]);

        $message = (new VerifyEmailNotification)->toMail($user)->toArray();

        $this->assertStringContainsString('Verify', (string) $message['subject']);
        $this->assertSame('Verify your email', $message['greeting']);
        $this->assertSame('Verify email', $message['actionText']);
        $this->assertStringContainsString(
            '60 minutes',
            json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        );
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
