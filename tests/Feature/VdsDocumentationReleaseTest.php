<?php

namespace Tests\Feature;

use Tests\TestCase;

class VdsDocumentationReleaseTest extends TestCase
{
    public function test_release_contains_matching_ubuntu_vds_guides(): void
    {
        $english = $this->readReleaseFile('docs/en/VDS_UBUNTU.md');
        $russian = $this->readReleaseFile('docs/ru/VDS_UBUNTU.md');

        foreach ([$english, $russian] as $guide) {
            $this->assertStringContainsString('/var/www/kaevcms/public', $guide);
            $this->assertStringNotContainsString('root /var/www/kaevcms;', $guide);
            $this->assertStringContainsString('php artisan kaevcms:update', $guide);
            $this->assertStringContainsString('deployment/vds/install-update-agent.sh', $guide);
            $this->assertStringContainsString('deployment/vds/remove-update-agent.sh', $guide);
            $this->assertStringContainsString('php artisan kaevcms:update-agent:status', $guide);
        }
    }

    private function readReleaseFile(string $path): string
    {
        $contents = file_get_contents(base_path($path));
        $this->assertIsString($contents);

        return $contents;
    }
}
