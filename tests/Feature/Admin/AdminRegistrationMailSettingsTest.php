<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\CmsSetting;
use App\Services\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminRegistrationMailSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_registration_and_mail_tabs(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/registration')
            ->assertOk()
            ->assertSee('Регистрация пользователей')
            ->assertSee('Требовать подтверждение регистрации по email')
            ->assertSee('Требования к логину')
            ->assertSee('от 3 до 32 символов')
            ->assertSee('Требования к паролю')
            ->assertSee('не менее 8 символов');

        $this->actingAs($admin, 'admin')
            ->get('/admin/settings/mail')
            ->assertOk()
            ->assertSee('Подключение SMTP')
            ->assertSee('Отправить тестовое письмо');
    }

    public function test_email_verification_cannot_be_enabled_with_registration_until_mail_is_tested(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->put('/admin/settings/registration', [
                'registration_enabled' => '1',
                'email_verification_required' => '1',
            ])
            ->assertSessionHasErrors('email_verification_required');

        $this->assertDatabaseMissing('cms_settings', [
            'key' => 'registration.enabled',
            'value' => '1',
        ]);
    }

    public function test_admin_can_save_encrypted_smtp_settings_and_send_test_mail(): void
    {
        Mail::fake();
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->put('/admin/settings/mail', [
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => '587',
                'encryption' => 'tls',
                'smtp_username' => 'no-reply@example.com',
                'smtp_password' => 'SecretPassword123',
                'from_address' => 'no-reply@example.com',
                'from_name' => 'L2 Test',
                'notification_email' => 'admin@example.com',
            ])
            ->assertRedirect(route('admin.settings.mail'))
            ->assertSessionHas('status');

        $encrypted = (string) CmsSetting::query()->where('key', MailSettings::KEY_PASSWORD)->value('value');
        $this->assertNotSame('SecretPassword123', $encrypted);
        $this->assertSame('SecretPassword123', Crypt::decryptString($encrypted));
        $this->assertDatabaseHas('cms_settings', ['key' => MailSettings::KEY_TESTED_AT, 'value' => null]);

        $this->actingAs($admin, 'admin')
            ->post('/admin/settings/mail/test', [
                'test_email' => 'recipient@example.com',
            ])
            ->assertRedirect(route('admin.settings.mail'))
            ->assertSessionHas('status');

        $this->assertNotNull(CmsSetting::query()->where('key', MailSettings::KEY_TESTED_AT)->value('value'));

        $this->actingAs($admin, 'admin')
            ->put('/admin/settings/registration', [
                'registration_enabled' => '1',
                'email_verification_required' => '1',
            ])
            ->assertRedirect(route('admin.settings.registration'));

        $this->assertDatabaseHas('cms_settings', ['key' => 'registration.enabled', 'value' => '1']);
        $this->assertDatabaseHas('cms_settings', ['key' => 'registration.email_verification_required', 'value' => '1']);
    }

    public function test_empty_password_field_keeps_existing_encrypted_password(): void
    {
        $admin = $this->createAdmin();
        $mailSettings = app(MailSettings::class);
        $mailSettings->update([
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailer',
            'password' => 'OriginalSecret123',
            'from_address' => 'no-reply@example.com',
            'from_name' => 'L2 Test',
            'admin_email' => '',
        ]);
        $before = CmsSetting::query()->where('key', MailSettings::KEY_PASSWORD)->value('value');

        $this->actingAs($admin, 'admin')->put('/admin/settings/mail', [
            'smtp_host' => 'smtp2.example.com',
            'smtp_port' => '465',
            'encryption' => 'ssl',
            'smtp_username' => 'mailer',
            'smtp_password' => '',
            'from_address' => 'no-reply@example.com',
            'from_name' => 'L2 Test',
            'notification_email' => '',
        ])->assertRedirect(route('admin.settings.mail'));

        $after = CmsSetting::query()->where('key', MailSettings::KEY_PASSWORD)->value('value');
        $this->assertSame($before, $after);
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
