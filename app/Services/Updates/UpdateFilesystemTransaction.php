<?php

namespace App\Services\Updates;

use RuntimeException;

final class UpdateFilesystemTransaction
{
    public function __construct(
        private readonly UpdateInstallationLayout $layout,
        private readonly UpdatePathPolicy $pathPolicy,
    ) {}

    /** @return array{backup_root: string, entries: list<array{target: string, existed: bool, sha256: string|null}>}|null */
    public function loadBackup(string $backupRoot): ?array
    {
        $manifestPath = rtrim($backupRoot, '/\\').DIRECTORY_SEPARATOR.'files.json';
        if (! is_file($manifestPath) || ! is_readable($manifestPath)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($decoded) || ($decoded['schema'] ?? null) !== 1 || ! is_array($decoded['entries'] ?? null)) {
            throw new RuntimeException(__('The update file backup metadata is invalid.'));
        }

        $entries = [];
        foreach ($decoded['entries'] as $entry) {
            if (! is_array($entry)
                || ! is_string($entry['target'] ?? null)
                || ! is_bool($entry['existed'] ?? null)
                || ! array_key_exists('sha256', $entry)
                || (! is_null($entry['sha256']) && ! is_string($entry['sha256']))
                || ! $this->pathPolicy->isSafeTarget($entry['target'])) {
                throw new RuntimeException(__('The update file backup metadata is invalid.'));
            }

            $sha256 = $entry['sha256'];
            if (($entry['existed'] && (! is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1))
                || (! $entry['existed'] && $sha256 !== null)) {
                throw new RuntimeException(__('The update file backup metadata is invalid.'));
            }

            $entries[] = [
                'target' => $entry['target'],
                'existed' => $entry['existed'],
                'sha256' => $sha256,
            ];
        }

        return ['backup_root' => rtrim($backupRoot, '/\\'), 'entries' => $entries];
    }

    /**
     * @param  list<array{source: string, target: string, sha256: string, size: int}>  $files
     * @param  list<string>  $delete
     * @return array{backup_root: string, entries: list<array{target: string, existed: bool, sha256: string|null}>}
     */
    public function backup(array $files, array $delete, string $backupRoot, UpdateLog $log): array
    {
        $entries = [];
        $targets = [];
        foreach ($files as $file) {
            $targets[] = $file['target'];
        }
        foreach ($delete as $target) {
            $targets[] = $target;
        }
        $targets = $this->minimalTargets($targets);

        foreach ($targets as $target) {
            $absolute = $this->layout->resolveTarget($target);
            $existed = file_exists($absolute) || is_link($absolute);
            if (! $existed) {
                $entries[] = ['target' => $target, 'existed' => false, 'sha256' => null];

                continue;
            }

            if (is_link($absolute)) {
                throw new RuntimeException(__('Refusing to update a symbolic link target: :target', ['target' => $target]));
            }

            $backupPath = $backupRoot.'/files/'.str_replace('/', DIRECTORY_SEPARATOR, $target);
            $this->copyPath($absolute, $backupPath);
            $entries[] = [
                'target' => $target,
                'existed' => true,
                'sha256' => $this->pathDigest($backupPath),
            ];
        }

        $metadata = [
            'schema' => 1,
            'created_at' => now()->utc()->toIso8601String(),
            'entries' => $entries,
        ];
        $encoded = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            throw new RuntimeException(__('Unable to encode the update file backup manifest.'));
        }

        if (! is_dir($backupRoot) && ! mkdir($backupRoot, 0775, true) && ! is_dir($backupRoot)) {
            throw new RuntimeException(__('Unable to create the update file backup directory.'));
        }

        if (file_put_contents($backupRoot.'/files.json', $encoded."\n", LOCK_EX) === false) {
            throw new RuntimeException(__('Unable to write the update file backup manifest.'));
        }

        $log->write('Application files selected for the update were backed up.');

        return ['backup_root' => $backupRoot, 'entries' => $entries];
    }

    /**
     * @param  list<array{source: string, target: string, sha256: string, size: int}>  $files
     * @param  list<string>  $delete
     */
    public function apply(array $files, array $delete, string $stagingPath, UpdateLog $log): void
    {
        foreach ($delete as $target) {
            $this->removePath($this->layout->resolveTarget($target));
            $log->write("Removed obsolete path: {$target}");
        }

        foreach ($files as $file) {
            $source = $stagingPath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file['source']);
            $destination = $this->layout->resolveTarget($file['target']);

            if (! is_file($source) || ! hash_equals($file['sha256'], (string) hash_file('sha256', $source))) {
                throw new RuntimeException(__('The staged update file is missing or damaged: :source', ['source' => $file['source']]));
            }

            if (is_dir($destination)) {
                throw new RuntimeException(__('An update file target is an existing directory: :target', ['target' => $file['target']]));
            }

            $directory = dirname($destination);
            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                throw new RuntimeException(__('Unable to create the update target directory: :target', ['target' => $file['target']]));
            }

            $permissions = is_file($destination) ? fileperms($destination) : false;
            $temporary = $destination.'.kaevcms-update-'.bin2hex(random_bytes(8));
            if (! copy($source, $temporary)) {
                throw new RuntimeException(__('Unable to write update target: :target', ['target' => $file['target']]));
            }

            if (! hash_equals($file['sha256'], (string) hash_file('sha256', $temporary))) {
                @unlink($temporary);

                throw new RuntimeException(__('The written update target failed integrity verification: :target', ['target' => $file['target']]));
            }

            if (is_int($permissions)) {
                @chmod($temporary, $permissions & 0777);
            }

            if (file_exists($destination) && ! unlink($destination)) {
                @unlink($temporary);

                throw new RuntimeException(__('Unable to replace update target: :target', ['target' => $file['target']]));
            }

            if (! rename($temporary, $destination)) {
                @unlink($temporary);

                throw new RuntimeException(__('Unable to activate update target: :target', ['target' => $file['target']]));
            }
        }

        $log->write('Application files were replaced successfully.');
    }

    /** @param array{backup_root: string, entries: list<array{target: string, existed: bool, sha256: string|null}>} $backup */
    public function verifyBackup(array $backup): void
    {
        foreach ($backup['entries'] as $entry) {
            if (! $entry['existed']) {
                continue;
            }

            $backupPath = $backup['backup_root'].'/files/'.str_replace('/', DIRECTORY_SEPARATOR, $entry['target']);
            if (! file_exists($backupPath)
                || ! is_string($entry['sha256'])
                || ! hash_equals($entry['sha256'], $this->pathDigest($backupPath))) {
                throw new RuntimeException(__('The update file backup failed integrity verification: :target', ['target' => $entry['target']]));
            }
        }
    }

    /** @param array{backup_root: string, entries: list<array{target: string, existed: bool, sha256: string|null}>} $backup */
    public function rollback(array $backup, UpdateLog $log): void
    {
        $this->verifyBackup($backup);

        foreach ($backup['entries'] as $entry) {
            $target = $entry['target'];
            $absolute = $this->layout->resolveTarget($target);
            $backupPath = $backup['backup_root'].'/files/'.str_replace('/', DIRECTORY_SEPARATOR, $target);

            if ($entry['existed']) {
                if (! file_exists($backupPath)
                    || ! is_string($entry['sha256'])
                    || ! hash_equals($entry['sha256'], $this->pathDigest($backupPath))) {
                    throw new RuntimeException(__('The update file backup failed integrity verification: :target', ['target' => $target]));
                }
            }

            $this->removePath($absolute);

            if ($entry['existed']) {
                $this->copyPath($backupPath, $absolute);
            }
        }

        $log->write('Application files were restored from the update backup.', 'WARN');
    }

    /**
     * @param  list<string>  $targets
     * @return list<string>
     */
    private function minimalTargets(array $targets): array
    {
        $targets = array_values(array_unique($targets));
        usort($targets, static fn (string $left, string $right): int => strlen($left) <=> strlen($right));

        $result = [];
        foreach ($targets as $target) {
            $targetKey = strtolower($target);
            $covered = false;
            foreach ($result as $existing) {
                if (str_starts_with($targetKey.'/', strtolower($existing).'/')) {
                    $covered = true;
                    break;
                }
            }

            if (! $covered) {
                $result[] = $target;
            }
        }

        return $result;
    }

    private function copyPath(string $source, string $destination): void
    {
        if (is_file($source)) {
            $directory = dirname($destination);
            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                throw new RuntimeException(__('Unable to create an update backup directory.'));
            }

            if (! copy($source, $destination)) {
                throw new RuntimeException(__('Unable to copy an update backup file.'));
            }

            $permissions = fileperms($source);
            if (is_int($permissions)) {
                @chmod($destination, $permissions & 0777);
            }

            return;
        }

        if (! is_dir($source)) {
            throw new RuntimeException(__('The update backup source is not a regular file or directory.'));
        }

        if (! is_dir($destination) && ! mkdir($destination, 0775, true) && ! is_dir($destination)) {
            throw new RuntimeException(__('Unable to create an update backup directory.'));
        }

        $permissions = fileperms($source);
        if (is_int($permissions)) {
            @chmod($destination, $permissions & 0777);
        }

        $items = scandir($source);
        if (! is_array($items)) {
            throw new RuntimeException(__('Unable to read an update backup directory.'));
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $childSource = $source.DIRECTORY_SEPARATOR.$item;
            if (is_link($childSource)) {
                throw new RuntimeException(__('Symbolic links cannot be backed up by the updater.'));
            }

            $this->copyPath($childSource, $destination.DIRECTORY_SEPARATOR.$item);
        }
    }

    private function pathDigest(string $path): string
    {
        if (is_link($path)) {
            throw new RuntimeException(__('Symbolic links cannot be verified by the updater.'));
        }

        if (is_file($path)) {
            $fileHash = hash_file('sha256', $path);
            if (! is_string($fileHash)) {
                throw new RuntimeException(__('Unable to calculate an update file backup checksum.'));
            }

            return hash('sha256', "file\0".$fileHash);
        }

        if (! is_dir($path)) {
            throw new RuntimeException(__('The update file backup is incomplete.'));
        }

        $items = scandir($path);
        if (! is_array($items)) {
            throw new RuntimeException(__('Unable to read an update backup directory.'));
        }

        $context = hash_init('sha256');
        hash_update($context, "directory\0");
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            hash_update($context, $item."\0".$this->pathDigest($path.DIRECTORY_SEPARATOR.$item)."\0");
        }

        return hash_final($context);
    }

    private function removePath(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (! unlink($path)) {
                throw new RuntimeException(__('Unable to remove an obsolete update file.'));
            }

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if (! is_array($items)) {
            throw new RuntimeException(__('Unable to read an obsolete update directory.'));
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->removePath($path.DIRECTORY_SEPARATOR.$item);
        }

        if (! rmdir($path)) {
            throw new RuntimeException(__('Unable to remove an obsolete update directory.'));
        }
    }
}
