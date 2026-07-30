<?php

namespace App\Services\Infrastructure;

use Illuminate\Foundation\Application;
use RuntimeException;
use Throwable;

final class RuntimeDirectoryManager
{
    public function __construct(private readonly Application $application) {}

    /** @var list<string> */
    private const DIRECTORIES = [
        'bootstrap/cache',
        'storage/framework/cache/data',
        'storage/framework/sessions',
        'storage/framework/views',
        'storage/logs',
    ];

    /** @return list<string> */
    public function relativeDirectories(): array
    {
        return self::DIRECTORIES;
    }

    /**
     * @return list<string>
     */
    public function ensure(bool $probe = false): array
    {
        $verified = [];

        foreach (self::DIRECTORIES as $relativePath) {
            $absolutePath = $this->application->basePath(str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
            $this->ensureDirectory($absolutePath, $relativePath);

            if ($probe) {
                $this->probeNestedWrite($absolutePath, $relativePath);
            }

            $verified[] = $relativePath;
        }

        return $verified;
    }

    private function ensureDirectory(string $absolutePath, string $relativePath): void
    {
        if (! is_dir($absolutePath) && ! @mkdir($absolutePath, 0775, true) && ! is_dir($absolutePath)) {
            throw new RuntimeException("Unable to create runtime directory: {$relativePath}");
        }

        if (! is_writable($absolutePath)) {
            throw new RuntimeException("Runtime directory is not writable: {$relativePath}");
        }
    }

    private function probeNestedWrite(string $absolutePath, string $relativePath): void
    {
        $probeRoot = null;
        $probeDirectory = null;
        $probeFile = null;

        try {
            $probeRoot = $absolutePath.DIRECTORY_SEPARATOR.'.kaevcms-runtime-probe-'.bin2hex(random_bytes(8));
            $probeDirectory = $probeRoot.DIRECTORY_SEPARATOR.'nested';
            $probeFile = $probeDirectory.DIRECTORY_SEPARATOR.'write-test';

            if (! @mkdir($probeDirectory, 0775, true) && ! is_dir($probeDirectory)) {
                throw new RuntimeException("Runtime directory cannot create nested directories: {$relativePath}");
            }

            if (@file_put_contents($probeFile, 'ok', LOCK_EX) === false || ! is_file($probeFile)) {
                throw new RuntimeException("Runtime directory cannot create files: {$relativePath}");
            }
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "Runtime directory write probe failed: {$relativePath}",
                previous: $exception,
            );
        } finally {
            if (is_string($probeFile) && is_file($probeFile)) {
                @unlink($probeFile);
            }
            if (is_string($probeDirectory) && is_dir($probeDirectory)) {
                @rmdir($probeDirectory);
            }
            if (is_string($probeRoot) && is_dir($probeRoot)) {
                @rmdir($probeRoot);
            }
        }
    }
}
