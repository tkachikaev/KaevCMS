<?php

namespace Tests\Feature\Admin;

use App\Mail\CustomHtmlMail;
use App\Models\Admin;
use App\Models\CmsSetting;
use App\Models\User;
use App\Notifications\MailTemplateTestNotification;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\CmsSettings;
use App\Services\MailSettings;
use App\Services\MailTemplateSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class MailTemplateSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_templates_have_ready_defaults_and_can_be_opened(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/mail/templates/email_verification')
            ->assertOk()
            ->assertSee('Подтверждение email')
            ->assertSee('Подтвердите регистрацию на {{site_name}}')
            ->assertSee('Восстановить стандартный шаблон')
            ->assertSee('Предпросмотр');

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/mail/templates/password_reset')
            ->assertOk()
            ->assertSee('Восстановление пароля');

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/mail/templates/password_changed')
            ->assertOk()
            ->assertSee('Пароль успешно изменён');
    }

    public function test_administrator_can_update_and_reset_mail_template(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->put('/admin/settings/mail/templates/email_verification', [
                'locale' => 'ru',
                'subject' => 'Добро пожаловать на {{site_name}}',
                'header' => 'Тестовый сервер',
                'heading' => 'Подтвердите адрес',
                'body' => 'Здравствуйте, {{username}}!',
                'action_text' => 'Завершить регистрацию',
                'footer' => 'Ссылка работает {{expires_in}}.',
            ])
            ->assertRedirect(route('admin.settings.mail.template', ['template' => 'email_verification', 'locale' => 'ru']))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('cms_settings', [
            'key' => 'mail.template.email_verification.ru.subject',
            'value' => 'Добро пожаловать на {{site_name}}',
        ]);
        $this->assertDatabaseHas('cms_settings', [
            'key' => 'mail.template.email_verification.ru.header',
            'value' => 'Тестовый сервер',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'mail.template_updated',
            'result' => 'success',
        ]);

        $this->actingAs($admin, 'admin')
            ->post('/admin/settings/mail/templates/email_verification/reset', ['locale' => 'ru'])
            ->assertRedirect(route('admin.settings.mail.template', ['template' => 'email_verification', 'locale' => 'ru']))
            ->assertSessionHas('status');

        $this->assertNull(CmsSetting::query()
            ->where('key', 'mail.template.email_verification.ru.subject')
            ->value('value'));
        $this->assertNull(CmsSetting::query()
            ->where('key', 'mail.template.email_verification.ru.header')
            ->value('value'));
        $this->assertSame(
            'Подтвердите регистрацию на {{site_name}}',
            app(MailTemplateSettings::class)->values('email_verification')['subject'],
        );
    }

    public function test_unknown_variables_and_html_are_rejected(): void
    {
        $admin = $this->createAdmin();
        $base = [
            'locale' => 'ru',
            'subject' => 'Письмо от {{site_name}}',
            'header' => '{{site_name}}',
            'heading' => 'Подтверждение',
            'body' => 'Здравствуйте, {{unknown_variable}}!',
            'action_text' => 'Подтвердить',
            'footer' => 'Текст',
        ];

        $this->actingAs($admin, 'admin')
            ->from('/admin/settings/mail/templates/email_verification')
            ->put('/admin/settings/mail/templates/email_verification', $base)
            ->assertRedirect('/admin/settings/mail/templates/email_verification')
            ->assertSessionHasErrors('body');

        $base['body'] = '<strong>Опасный HTML</strong>';

        $this->actingAs($admin, 'admin')
            ->from('/admin/settings/mail/templates/email_verification')
            ->put('/admin/settings/mail/templates/email_verification', $base)
            ->assertRedirect('/admin/settings/mail/templates/email_verification')
            ->assertSessionHasErrors('body');
    }

    public function test_saved_templates_are_used_by_user_notifications(): void
    {
        app(CmsSettings::class)->setMany([
            'site.name' => 'Eternal World',
            'site.name.ru' => 'Eternal World',
        ]);

        $templates = app(MailTemplateSettings::class);
        $templates->update(MailTemplateSettings::EMAIL_VERIFICATION, [
            'subject' => 'Проверка {{site_name}} для {{username}}',
            'header' => '{{site_name}}',
            'heading' => 'Новый заголовок',
            'body' => 'Пользователь: {{user_email}}',
            'action_text' => 'Проверить адрес',
            'footer' => 'Срок: {{expires_in}}',
        ]);
        $templates->update(MailTemplateSettings::PASSWORD_RESET, [
            'subject' => 'Сброс для {{username}}',
            'header' => '{{site_name}}',
            'heading' => 'Установите пароль',
            'body' => 'Адрес: {{user_email}}',
            'action_text' => 'Открыть восстановление',
            'footer' => 'Срок: {{expires_in}}',
        ]);

        $user = User::query()->create([
            'name' => 'player',
            'email' => 'player@example.com',
            'password' => Hash::make('Password123'),
        ]);

        $verification = (new VerifyEmailNotification)->toMail($user);
        $reset = (new ResetPasswordNotification('example-token'))->toMail($user);

        $verificationData = $verification->toArray();
        $resetData = $reset->toArray();

        $this->assertStringContainsString('Проверка', (string) $verificationData['subject']);
        $this->assertSame('Новый заголовок', $verificationData['greeting']);
        $this->assertSame('Проверить адрес', $verificationData['actionText']);
        $this->assertSame('Eternal World', $verification->data()['brandName']);
        $this->assertStringContainsString('Eternal World', (string) $verification->render());
        $this->assertSame('Сброс для player', $resetData['subject']);
        $this->assertSame('Открыть восстановление', $resetData['actionText']);
    }

    public function test_administrator_can_send_saved_template_to_test_address(): void
    {
        Notification::fake();
        $admin = $this->createAdmin();
        $this->configureReadyMail();

        $this->actingAs($admin, 'admin')
            ->post('/admin/settings/mail/templates/password_reset/test', [
                'test_email' => 'recipient@example.com',
                'locale' => 'ru',
            ])
            ->assertRedirect(route('admin.settings.mail.template', ['template' => 'password_reset', 'locale' => 'ru']))
            ->assertSessionHas('status');

        Notification::assertSentOnDemand(MailTemplateTestNotification::class);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'mail.template_test_sent',
            'result' => 'success',
        ]);
    }

    public function test_administrator_can_send_one_custom_html_email(): void
    {
        Mail::fake();
        $admin = $this->createAdmin();
        $this->configureReadyMail();

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/mail/custom')
            ->assertOk()
            ->assertSee('Произвольное HTML-письмо')
            ->assertSee('HTML-код');

        $this->actingAs($admin, 'admin')
            ->post('/admin/settings/mail/custom', [
                'recipient' => 'recipient@example.com',
                'subject' => 'Новость сервера',
                'html' => '<!doctype html><html><body><h1>Событие</h1><p>Текст письма.</p></body></html>',
            ])
            ->assertRedirect(route('admin.settings.mail.custom'))
            ->assertSessionHas('status');

        Mail::assertSent(CustomHtmlMail::class, function (CustomHtmlMail $mail): bool {
            return $mail->hasTo('recipient@example.com')
                && $mail->hasSubject('Новость сервера')
                && str_contains($mail->htmlContent, '<h1>Событие</h1>');
        });

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'mail.custom_sent',
            'target_name' => 'recipient@example.com',
            'result' => 'success',
        ]);
    }

    public function test_custom_html_email_rejects_executable_code(): void
    {
        Mail::fake();
        $admin = $this->createAdmin();
        $this->configureReadyMail();

        $this->actingAs($admin, 'admin')
            ->from('/admin/settings/mail/custom')
            ->post('/admin/settings/mail/custom', [
                'recipient' => 'recipient@example.com',
                'subject' => 'Unsafe',
                'html' => '<html><body><script>alert(1)</script><p>Text</p></body></html>',
            ])
            ->assertRedirect('/admin/settings/mail/custom')
            ->assertSessionHasErrors('html');

        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'mail.custom_sent',
        ]);
    }

    public function test_password_change_sends_configured_notification_without_blocking_reset(): void
    {
        Notification::fake();
        $this->configureReadyMail();
        $user = User::query()->create([
            'name' => 'player',
            'email' => 'player@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123'),
        ]);
        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassword123',
            'password_confirmation' => 'NewPassword123',
        ])->assertRedirect(route('login'));

        Notification::assertSentTo($user, PasswordChangedNotification::class);
        $this->assertTrue(Hash::check('NewPassword123', $user->fresh()->password));
    }

    private function configureReadyMail(): void
    {
        $mail = app(MailSettings::class);
        $mail->update([
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => '',
            'password' => null,
            'from_address' => 'no-reply@example.com',
            'from_name' => 'L2 Test',
            'admin_email' => 'admin@example.com',
        ]);
        $mail->markTested();
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
}
