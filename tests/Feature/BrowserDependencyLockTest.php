<?php

namespace Tests\Feature;

use Tests\TestCase;

class BrowserDependencyLockTest extends TestCase
{
    public function test_browser_dependency_lock_uses_the_public_npm_registry(): void
    {
        $lock = file_get_contents(base_path('package-lock.json'));

        $this->assertNotFalse($lock);
        $this->assertStringNotContainsString('applied-caas-gateway', $lock);
        $this->assertStringNotContainsString('.internal.api.openai.org', $lock);
        $this->assertStringContainsString('https://registry.npmjs.org/@playwright/test/', $lock);
        $this->assertStringContainsString('https://registry.npmjs.org/playwright-core/', $lock);
    }

    public function test_browser_setup_forces_dev_dependencies_and_quality_checks_the_installed_package(): void
    {
        $setup = file_get_contents(base_path('deployment/windows/browser-setup.ps1'));
        $quality = file_get_contents(base_path('deployment/windows/browser-quality.ps1'));

        $this->assertNotFalse($setup);
        $this->assertNotFalse($quality);
        $this->assertStringContainsString('npm ci --include=dev', $setup);
        $this->assertStringContainsString('npm exec -- playwright install chromium', $setup);
        $this->assertStringContainsString('node_modules\\@playwright\\test\\package.json', $quality);
        $this->assertStringNotContainsString("require.resolve('@playwright/test')", $quality);
        $this->assertStringContainsString('expected after extracting a fresh ZIP', $quality);
    }
}
