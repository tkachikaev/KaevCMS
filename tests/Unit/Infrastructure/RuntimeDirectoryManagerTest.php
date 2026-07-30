<?php

namespace Tests\Unit\Infrastructure;

use App\Services\Infrastructure\RuntimeDirectoryManager;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

final class RuntimeDirectoryManagerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kaevcms-runtime-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function test_it_creates_and_write_tests_required_runtime_directories(): void
    {
        $manager = new RuntimeDirectoryManager(new Application($this->root));

        $verified = $manager->ensure(true);

        $this->assertSame([
            'bootstrap/cache',
            'storage/framework/cache/data',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/logs',
        ], $verified);

        foreach ($verified as $relativePath) {
            $absolutePath = $this->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $this->assertDirectoryExists($absolutePath);
            $this->assertSame([], glob($absolutePath.DIRECTORY_SEPARATOR.'.kaevcms-runtime-probe-*') ?: []);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($child) && ! is_link($child)) {
                $this->removeDirectory($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
