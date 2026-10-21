<?php

namespace App\Services\Updates;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Assembles an update package that the browser sends in parts smaller than PHP's
 * upload_max_filesize / post_max_size, so packages of any size can be uploaded on
 * shared hosting without changing PHP settings. Parts must arrive in order.
 */
final class UpdateChunkedUpload
{
    private const STALE_SECONDS = 86400;

    public function __construct(private readonly UpdateUploadLimit $uploadLimit) {}

    /**
     * Appends one part. Returns the assembled file once the last part has arrived.
     */
    public function append(string $uploadId, int $index, int $total, int $size, UploadedFile $part): ?string
    {
        $maximumBytes = max(1024, (int) config('cms.updates.maximum_upload_kilobytes', 524288)) * 1024;
        if (preg_match('/\A[a-f0-9]{32}\z/', $uploadId) !== 1 || $total < 1 || $index < 0 || $index >= $total || $size < 1 || $size > $maximumBytes) {
            throw new RuntimeException(__('The upload was interrupted or arrived out of order. Start the upload again.'));
        }
        if (! $part->isValid() || (int) $part->getSize() > $this->uploadLimit->bytes()) {
            throw new RuntimeException(__('An upload part is larger than this server accepts. Start the upload again.'));
        }

        $directory = $this->directory();
        if (! is_dir($directory) && ! @mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException(__('Unable to create the update package directory.'));
        }

        $partPath = $directory.DIRECTORY_SEPARATOR.$uploadId.'.part';
        $statePath = $directory.DIRECTORY_SEPARATOR.$uploadId.'.json';

        if ($index === 0) {
            $this->removeStale($directory);
            $this->discard($uploadId);
            $state = ['total' => $total, 'size' => $size, 'next' => 0];
        } else {
            $state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : null;
            if (! is_array($state) || ($state['total'] ?? null) !== $total || ($state['size'] ?? null) !== $size || ($state['next'] ?? null) !== $index) {
                $this->discard($uploadId);

                throw new RuntimeException(__('The upload was interrupted or arrived out of order. Start the upload again.'));
            }
        }

        $source = fopen($part->getRealPath(), 'rb');
        $target = fopen($partPath, 'ab');
        if ($source === false || $target === false) {
            throw new RuntimeException(__('Unable to store the uploaded update package.'));
        }
        try {
            $copied = stream_copy_to_stream($source, $target);
        } finally {
            fclose($source);
            fclose($target);
        }
        clearstatcache(true, $partPath);
        if ($copied === false || (int) filesize($partPath) > $size) {
            $this->discard($uploadId);

            throw new RuntimeException(__('The uploaded parts do not add up to the package size. Start the upload again.'));
        }

        $state['next'] = $index + 1;
        if ($state['next'] < $total) {
            file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));

            return null;
        }

        @unlink($statePath);
        if ((int) filesize($partPath) !== $size) {
            $this->discard($uploadId);

            throw new RuntimeException(__('The uploaded parts do not add up to the package size. Start the upload again.'));
        }

        return $partPath;
    }

    public function discard(string $uploadId): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $uploadId) !== 1) {
            return;
        }

        foreach (['.part', '.json'] as $suffix) {
            $path = $this->directory().DIRECTORY_SEPARATOR.$uploadId.$suffix;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function directory(): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.'kaevcms'.DIRECTORY_SEPARATOR.'updates'.DIRECTORY_SEPARATOR.'chunks');
    }

    /** Abandoned uploads do not accumulate. */
    private function removeStale(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $name) {
            $path = $directory.DIRECTORY_SEPARATOR.$name;
            if (preg_match('/\A[a-f0-9]{32}\.(?:part|json)\z/', $name) === 1 && is_file($path) && (int) filemtime($path) < time() - self::STALE_SECONDS) {
                @unlink($path);
            }
        }
    }
}
