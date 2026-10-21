<?php

namespace App\Services\Updates;

use RuntimeException;

/**
 * A folder where the owner can place an update ZIP through FTP, SFTP or the hosting
 * file manager. Packages taken from it skip the browser upload and its PHP size limits;
 * they pass exactly the same inspection as uploaded ones.
 */
final class UpdateIncomingPackages
{
    private const NAME_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,199}\.zip\z/';

    public function directory(): string
    {
        $configured = trim((string) config('cms.updates.incoming_directory', ''));

        $directory = $configured !== '' ? $configured : storage_path('app/kaevcms/updates/incoming');

        // Shown to the owner as the FTP target, so use the server's own separators.
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $directory), DIRECTORY_SEPARATOR);
    }

    /**
     * Creates the folder so the owner can find it; failures are reported by the page.
     */
    public function ensureDirectory(): bool
    {
        $directory = $this->directory();

        return is_dir($directory) || @mkdir($directory, 0770, true) || is_dir($directory);
    }

    /**
     * @return list<array{name: string, size: int, modified_at: int}>
     */
    public function packages(): array
    {
        $directory = $this->directory();
        if (! is_dir($directory)) {
            return [];
        }

        $packages = [];
        foreach (scandir($directory) ?: [] as $name) {
            $path = $directory.DIRECTORY_SEPARATOR.$name;
            if (preg_match(self::NAME_PATTERN, $name) !== 1 || is_link($path) || ! is_file($path)) {
                continue;
            }

            $packages[] = [
                'name' => $name,
                'size' => (int) filesize($path),
                'modified_at' => (int) filemtime($path),
            ];
        }

        usort($packages, static fn (array $left, array $right): int => $right['modified_at'] <=> $left['modified_at']);

        return $packages;
    }

    /**
     * Resolves a file name chosen in the panel to a package inside the folder. Only plain
     * ZIP files directly in the folder qualify; paths, links and other files are refused.
     */
    public function path(string $name): string
    {
        $directory = realpath($this->directory());
        if ($directory === false || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new RuntimeException(__('The selected update package was not found in the upload folder.'));
        }

        $path = $directory.DIRECTORY_SEPARATOR.$name;
        if (is_link($path) || ! is_file($path) || dirname((string) realpath($path)) !== $directory) {
            throw new RuntimeException(__('The selected update package was not found in the upload folder.'));
        }

        $maximumBytes = max(1024, (int) config('cms.updates.maximum_upload_kilobytes', 524288)) * 1024;
        if ((int) filesize($path) > $maximumBytes) {
            throw new RuntimeException(__('The update package is larger than the configured maximum.'));
        }

        return $path;
    }
}
