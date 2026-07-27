<?php

namespace Tests\Unit\Updates;

use App\Services\Updates\UpdatePathPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UpdatePathPolicyTest extends TestCase
{
    #[DataProvider('safeTargets')]
    public function test_release_targets_are_accepted(string $target): void
    {
        $this->assertTrue((new UpdatePathPolicy)->isSafeTarget($target));
    }

    #[DataProvider('unsafeTargets')]
    public function test_runtime_and_traversal_targets_are_rejected(string $target): void
    {
        $this->assertFalse((new UpdatePathPolicy)->isSafeTarget($target));
    }

    #[DataProvider('filesystemPaths')]
    public function test_absolute_filesystem_paths_are_detected(string $path, bool $expected): void
    {
        $this->assertSame($expected, (new UpdatePathPolicy)->isAbsoluteFilesystemPath($path));
    }

    /** @return array<string, array{string, bool}> */
    public static function filesystemPaths(): array
    {
        return [
            'unix absolute' => ['/var/lib/kaevcms/database.sqlite', true],
            'windows backslash absolute' => ['C:\\KaevCMS\database.sqlite', true],
            'windows slash absolute' => ['D:/KaevCMS/database.sqlite', true],
            'windows UNC absolute' => ['\\\\server\share\database.sqlite', true],
            'relative' => ['database/database.sqlite', false],
        ];
    }

    /** @return array<string, array{string}> */
    public static function safeTargets(): array
    {
        return [
            'application file' => ['core/app/Services/Example.php'],
            'environment template' => ['core/.env.example'],
            'migration' => ['core/database/migrations/2026_07_23_000000_example.php'],
            'public asset' => ['public/assets/admin/css/base.css'],
            'uploads Apache protection' => ['public/uploads/.htaccess'],
            'uploads release placeholder' => ['public/uploads/.gitignore'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function unsafeTargets(): array
    {
        return [
            'environment' => ['core/.env'],
            'environment backup' => ['core/.env.production'],
            'storage' => ['core/storage/logs/laravel.log'],
            'vendor' => ['core/vendor/autoload.php'],
            'sqlite runtime' => ['core/database/database.sqlite'],
            'custom sqlite runtime' => ['core/database/production.sqlite'],
            'nested public tree' => ['core/public/index.php'],
            'git metadata' => ['core/.git/config'],
            'split path configuration' => ['core/bootstrap/kaevcms-public-path.php'],
            'bootstrap cache' => ['core/bootstrap/cache/config.php'],
            'uploads' => ['public/uploads/news/cover.webp'],
            'game asset uploads' => ['public/uploads/game-assets/items/common/etc_adena_i00.webp'],
            'public storage' => ['public/storage/private.txt'],
            'shared-hosting core locator' => ['public/kaevcms-path.php'],
            'traversal' => ['core/../.env'],
            'absolute' => ['/core/app.php'],
            'windows absolute' => ['C:/core/app.php'],
            'unknown root' => ['resources/view.blade.php'],
        ];
    }
}
