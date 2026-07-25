<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\MailSettings;
use App\Services\Security\EncryptionHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EncryptionHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_encrypted_settings_and_credentials_are_checked_without_exposing_values(): void
    {
        app(MailSettings::class)->update([
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailer',
            'password' => 'MailSecret123',
            'from_address' => 'no-reply@example.test',
            'from_name' => 'KaevCMS',
            'admin_email' => '',
        ]);

        $loginServer = LoginServer::factory()->create([
            'database_password' => 'LoginSecret123',
        ]);
        GameServer::factory()->for($loginServer)->create([
            'use_login_server_connection' => false,
            'database_host' => '127.0.0.1',
            'database_port' => 3306,
            'database_name' => 'game',
            'database_username' => 'cms',
            'database_password' => 'GameSecret123',
            'database_charset' => 'utf8mb4',
        ]);
        Admin::factory()->create([
            'two_factor_secret' => 'TwoFactorSecret',
            'two_factor_recovery_codes' => ['hash-one', 'hash-two'],
            'two_factor_confirmed_at' => now(),
        ]);

        $health = app(EncryptionHealth::class)->inspect();

        $this->assertTrue($health['app_key_configured']);
        $this->assertSame('success', $health['state']);
        $this->assertSame(5, $health['encrypted_values_total']);
        $this->assertSame(0, $health['invalid_values_total']);
        $this->assertStringNotContainsString('MailSecret123', $health['details']);
        $this->assertStringNotContainsString('GameSecret123', $health['details']);
    }

    public function test_unreadable_encrypted_values_are_reported_by_category(): void
    {
        $loginServer = LoginServer::factory()->create();
        $admin = Admin::factory()->create([
            'two_factor_secret' => 'TwoFactorSecret',
            'two_factor_confirmed_at' => now(),
        ]);

        DB::table('login_servers')->where('id', $loginServer->id)->update([
            'database_password' => 'not-a-valid-laravel-payload',
        ]);
        DB::table('admins')->where('id', $admin->id)->update([
            'two_factor_secret' => 'not-a-valid-laravel-payload',
        ]);

        $health = app(EncryptionHealth::class)->inspect();
        /** @var array<string, int> $invalidByCategory */
        $invalidByCategory = collect($health['categories'])->pluck('invalid', 'key')->all();

        $this->assertSame('danger', $health['state']);
        $this->assertSame(2, $health['invalid_values_total']);
        $this->assertSame(1, $invalidByCategory['login_server_passwords']);
        $this->assertSame(1, $invalidByCategory['admin_two_factor_secrets']);

        $this->artisan('kaevcms:encryption-health')
            ->expectsOutputToContain(trans_choice(
                ':count encrypted value cannot be decrypted with the current APP_KEY.|:count encrypted values cannot be decrypted with the current APP_KEY.',
                2,
                ['count' => 2],
            ))
            ->assertFailed();
    }

    public function test_missing_app_key_is_reported_even_when_no_secrets_exist(): void
    {
        config()->set('app.key', '');

        $health = app(EncryptionHealth::class)->inspect();

        $this->assertFalse($health['app_key_configured']);
        $this->assertSame('danger', $health['state']);
        $this->assertSame(__('APP_KEY is missing'), $health['status']);
    }

    public function test_database_failure_is_reported_without_exposing_connection_details(): void
    {
        $originalConnection = (string) config('database.default');
        config()->set('database.default', 'missing-encryption-health-connection');

        try {
            $health = app(EncryptionHealth::class)->inspect();
        } finally {
            config()->set('database.default', $originalConnection);
        }

        $this->assertSame('danger', $health['state']);
        $this->assertSame(__('Encryption check unavailable'), $health['status']);
        $this->assertSame([], $health['categories']);
        $this->assertStringNotContainsString('missing-encryption-health-connection', $health['details']);
    }
}
