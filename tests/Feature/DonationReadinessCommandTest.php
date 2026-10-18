<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class DonationReadinessCommandTest extends TestCase
{
    public function test_fresh_process_without_an_enabled_module_reports_blockers_instead_of_crashing(): void
    {
        // A child process has no module classes registered by another test.
        // Its private in-memory database deliberately has no installed tables.
        $process = new Process([PHP_BINARY, base_path('artisan'), 'kaevcms:donations-readiness'], base_path(), [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ], timeout: 30);
        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertStringContainsString('[BLOCKED] Payment mode selected in Donations admin', $output);
        $this->assertStringContainsString('[BLOCKED] Donation and Wallet database tables installed', $output);
        $this->assertStringContainsString('No settings were changed.', $output);
        $this->assertStringNotContainsString('does not exist', $output);
    }
}
