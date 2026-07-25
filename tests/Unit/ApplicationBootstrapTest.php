<?php

namespace Tests\Unit;

use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class ApplicationBootstrapTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_console_kernel_resolves_before_configuration_bootstrappers_run(): void
    {
        $application = require dirname(__DIR__, 2).'/bootstrap/app.php';

        $this->assertInstanceOf(Kernel::class, $application->make(Kernel::class));
    }
}
