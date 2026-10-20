<?php

namespace App\Services\Updates;

use RuntimeException;
use ZipArchive;

/**
 * Replaces the production Composer dependencies (vendor/) shipped inside a cumulative
 * update package when the package changes composer.lock.
 *
 * The new tree is unpacked next to the live one and swapped in with two renames on the
 * same filesystem, so the switch is near-atomic and the previous tree stays available for
 * rollback. The handoff state is kept in the update backup directory, which lets a fresh
 * PHP process finish (or roll back) the update after the swap: the process that performed
 * the swap still holds the previous framework classes and must not keep using them.
 */
final class UpdateDependencyBundle
{
    public const STATE_FILE = 'dependencies.json';

    private const NEXT_SUFFIX = '.kaevcms-next';

    private const PREVIOUS_SUFFIX = '.kaevcms-previous';

    private const FAILED_SUFFIX = '.kaevcms-failed';

    public function __construct(
        private readonly UpdateInstallationLayout $layout,
        private readonly UpdatePathPolicy $pathPolicy,
        private readonly ?string $vendorRootOverride = null,
    ) {}

    public function vendorRoot(): string
    {
        return $this->vendorRootOverride ?? $this->layout->coreRoot().DIRECTORY_SEPARATOR.'vendor';
    }

    /**
     * Unpacks and validates the bundle into a sibling directory of vendor/.
     *
     * @param  array{bundle_path: string, files: int, bytes: int}  $dependencies
     */
    public function stage(array $dependencies, UpdateLog $log): string
    {
        $next = $this->vendorRoot().self::NEXT_SUFFIX;
        $this->removeTree($next);

        $zip = new ZipArchive;
        if ($zip->open($dependencies['bundle_path'], ZipArchive::RDONLY) !== true) {
            throw new RuntimeException(__('The dependency bundle in the update package is not a readable ZIP archive.'));
        }

        try {
            if ($zip->numFiles !== $dependencies['files']) {
                throw new RuntimeException(__('The dependency bundle does not match its manifest.'));
            }

            $bytes = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new RuntimeException(__('The dependency bundle contains an unreadable entry.'));
                }

                $name = $stat['name'];
                if (! $this->pathPolicy->isSafeArchivePath($name) || ! str_starts_with($name, 'vendor/')) {
                    throw new RuntimeException(__('Unsafe dependency bundle path: :path', ['path' => $name]));
                }

                $operatingSystem = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)
                    && (($attributes >> 16) & 0xF000) === 0xA000) {
                    throw new RuntimeException(__('Symbolic links are not allowed in update packages: :path', ['path' => $name]));
                }

                $bytes += max(0, $stat['size']);
                if ($bytes > $dependencies['bytes']) {
                    throw new RuntimeException(__('The dependency bundle does not match its manifest.'));
                }

                $relative = substr($name, strlen('vendor/'));
                if ($relative === '' || str_ends_with($name, '/')) {
                    $this->ensureDirectory($next.($relative === '' ? '' : DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, rtrim($relative, '/'))));

                    continue;
                }

                $destination = $next.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $this->ensureDirectory(dirname($destination));

                $source = $zip->getStream($name);
                $target = fopen($destination, 'wb');
                if (! is_resource($source) || ! is_resource($target)) {
                    throw new RuntimeException(__('Unable to unpack the dependency bundle: :path', ['path' => $name]));
                }
                $copied = stream_copy_to_stream($source, $target);
                fclose($source);
                fclose($target);
                if ($copied !== $stat['size']) {
                    throw new RuntimeException(__('Unable to unpack the dependency bundle: :path', ['path' => $name]));
                }
            }

            if ($bytes !== $dependencies['bytes']) {
                throw new RuntimeException(__('The dependency bundle does not match its manifest.'));
            }
        } catch (RuntimeException $exception) {
            $zip->close();
            $this->removeTree($next);

            throw $exception;
        }

        $zip->close();

        foreach (['autoload.php', 'composer'.DIRECTORY_SEPARATOR.'installed.json', 'composer'.DIRECTORY_SEPARATOR.'autoload_real.php'] as $required) {
            if (! is_file($next.DIRECTORY_SEPARATOR.$required)) {
                $this->removeTree($next);

                throw new RuntimeException(__('The dependency bundle is incomplete: :path', ['path' => 'vendor/'.str_replace(DIRECTORY_SEPARATOR, '/', $required)]));
            }
        }

        $log->write("Dependency bundle unpacked: {$dependencies['files']} entries.");

        return $next;
    }

    /**
     * Moves the staged tree into place and records the handoff state.
     *
     * @param  array<string, mixed>  $handoff
     */
    public function swap(string $staged, string $backupRoot, string $uuid, array $handoff, UpdateLog $log): void
    {
        $vendor = $this->vendorRoot();
        $previous = $vendor.self::PREVIOUS_SUFFIX;
        if (! is_dir($staged) || is_link($staged)) {
            throw new RuntimeException(__('The staged dependency tree is missing.'));
        }
        if (! is_dir($vendor) || is_link($vendor)) {
            throw new RuntimeException(__('The installed vendor directory is missing or is a symbolic link.'));
        }

        $this->removeTree($previous);
        $this->writeState($backupRoot, [
            'schema' => 1,
            'uuid' => $uuid,
            'swapped' => false,
            ...$handoff,
        ]);

        if (! @rename($vendor, $previous)) {
            throw new RuntimeException(__('Unable to move the installed dependencies aside.'));
        }

        if (! @rename($staged, $vendor)) {
            if (! @rename($previous, $vendor)) {
                throw new RuntimeException(__('Unable to restore the installed dependencies after a failed swap. Restore vendor.kaevcms-previous manually.'));
            }

            throw new RuntimeException(__('Unable to move the new dependencies into place.'));
        }

        $this->writeState($backupRoot, [
            'schema' => 1,
            'uuid' => $uuid,
            'swapped' => true,
            ...$handoff,
        ]);
        $this->clearBootstrapCaches();
        $log->write('Composer dependencies replaced; the previous vendor directory is kept for rollback.');
    }

    /** @return array<string, mixed>|null */
    public function state(string $backupRoot): ?array
    {
        $path = rtrim($backupRoot, '/\\').DIRECTORY_SEPARATOR.self::STATE_FILE;
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ($decoded['schema'] ?? null) !== 1 || ! is_bool($decoded['swapped'] ?? null)) {
            throw new RuntimeException(__('The dependency update state is invalid.'));
        }

        return $decoded;
    }

    /** Puts the previous dependency tree back. Safe to call when nothing was swapped. */
    public function restore(string $backupRoot, UpdateLog $log): void
    {
        $vendor = $this->vendorRoot();
        $previous = $vendor.self::PREVIOUS_SUFFIX;
        $state = $this->state($backupRoot);
        $this->removeTree($vendor.self::NEXT_SUFFIX);

        if ($state === null || $state['swapped'] !== true) {
            return;
        }

        if (! is_dir($previous)) {
            throw new RuntimeException(__('The previous vendor directory is missing; dependencies cannot be rolled back.'));
        }

        $failed = $vendor.self::FAILED_SUFFIX;
        $this->removeTree($failed);
        if (is_dir($vendor) && ! @rename($vendor, $failed)) {
            throw new RuntimeException(__('Unable to move the new dependencies aside during rollback.'));
        }
        if (! @rename($previous, $vendor)) {
            throw new RuntimeException(__('Unable to restore the previous dependencies. Restore vendor.kaevcms-previous manually.'));
        }

        $this->removeTree($failed);
        $state['swapped'] = false;
        $state['restored'] = true;
        $this->writeState($backupRoot, $state);
        $this->clearBootstrapCaches();
        $log->write('Previous Composer dependencies were restored.', 'WARN');
    }

    /** Removes the kept previous tree after a successful update. */
    public function discardPrevious(UpdateLog $log): void
    {
        $previous = $this->vendorRoot().self::PREVIOUS_SUFFIX;
        if (is_dir($previous)) {
            $this->removeTree($previous);
            $log->write('Previous vendor directory removed after a successful update.');
        }
    }

    /**
     * Package discovery and config caches list service providers of the previous
     * dependencies; a provider removed by the update would break the next boot.
     */
    public function clearBootstrapCaches(): void
    {
        $cache = $this->layout->coreRoot().DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'cache';
        foreach (glob($cache.DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
            if (is_file($file) && ! is_link($file)) {
                @unlink($file);
            }
        }
    }

    /** @param array<string, mixed> $state */
    private function writeState(string $backupRoot, array $state): void
    {
        if (! is_dir($backupRoot) && ! mkdir($backupRoot, 0700, true) && ! is_dir($backupRoot)) {
            throw new RuntimeException(__('Unable to create the update backup directory.'));
        }

        $path = rtrim($backupRoot, '/\\').DIRECTORY_SEPARATOR.self::STATE_FILE;
        $temporary = $path.'.tmp';
        $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || file_put_contents($temporary, $encoded."\n", LOCK_EX) === false || ! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException(__('Unable to record the dependency update state.'));
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            @chmod($path, 0600);
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0770, true) && ! is_dir($path)) {
            throw new RuntimeException(__('Unable to unpack the dependency bundle.'));
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->removeTree($path.DIRECTORY_SEPARATOR.$item);
        }

        @rmdir($path);
    }
}
