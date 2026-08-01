<?php

namespace Tests\Unit\Diagnostics;

use App\Services\Diagnostics\DiagnosticRedactor;
use Tests\TestCase;

class DiagnosticRedactorTest extends TestCase
{
    public function test_secrets_personal_data_and_absolute_paths_are_removed_without_hiding_safe_collections(): void
    {
        $redactor = new DiagnosticRedactor;
        $result = $redactor->value([
            'app_key' => 'base64:VerySensitiveApplicationKey1234567890=',
            'database_password' => 'database-secret',
            'login_servers' => [
                ['name' => 'Main LoginServer', 'status' => 'configured'],
            ],
            'message' => implode(' ', [
                'password=plain-secret',
                'token=token-secret',
                'owner@example.com',
                '192.0.2.25',
                base_path('storage/logs/laravel.log'),
            ]),
        ]);

        $this->assertSame('[REDACTED]', $result['app_key']);
        $this->assertSame('[REDACTED]', $result['database_password']);
        $this->assertSame('Main LoginServer', $result['login_servers'][0]['name']);
        $this->assertStringContainsString('password=[REDACTED]', $result['message']);
        $this->assertStringContainsString('token=[REDACTED]', $result['message']);
        $this->assertStringContainsString('[EMAIL]', $result['message']);
        $this->assertStringContainsString('[IP]', $result['message']);
        $this->assertStringContainsString('[PROJECT]', $result['message']);
        $this->assertStringNotContainsString('plain-secret', $result['message']);
        $this->assertStringNotContainsString('owner@example.com', $result['message']);
        $this->assertStringNotContainsString(base_path(), $result['message']);
    }

    public function test_real_ips_are_redacted_without_corrupting_times_or_software_versions(): void
    {
        $redactor = new DiagnosticRedactor;
        $result = $redactor->text(implode(' | ', [
            '2026-08-01T15:49:48+03:00',
            'Linux 7.0.0-28-generic',
            'MySQL 8.4.10-0ubuntu0.26.04.1',
            'IPv4 192.0.2.25',
            'IPv6 2001:db8::25',
        ]));

        $this->assertStringContainsString('2026-08-01T15:49:48+03:00', $result);
        $this->assertStringContainsString('Linux 7.0.0-28-generic', $result);
        $this->assertStringContainsString('MySQL 8.4.10-0ubuntu0.26.04.1', $result);
        $this->assertSame(2, substr_count($result, '[IP]'));
        $this->assertStringNotContainsString('192.0.2.25', $result);
        $this->assertStringNotContainsString('2001:db8::25', $result);
    }
}
