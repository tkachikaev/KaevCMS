<?php

namespace App\Services\Updates;

use RuntimeException;

final class UpdateLog
{
    private string $path;

    public function __construct(string $uuid)
    {
        $directory = storage_path('logs');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(__('Unable to create the update log directory.'));
        }

        $this->path = $directory.'/update-'.$uuid.'.log';
    }

    public function write(string $message, string $level = 'INFO'): void
    {
        $line = sprintf(
            "[%s] [%s] %s\n",
            now()->utc()->format('Y-m-d\TH:i:s\Z'),
            strtoupper($level),
            str_replace(["\r", "\n"], ' ', $message),
        );

        if (file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException(__('Unable to write the update log.'));
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    public function relativePath(): string
    {
        $storage = rtrim(str_replace('\\', '/', storage_path()), '/').'/';
        $path = str_replace('\\', '/', $this->path);

        return str_starts_with($path, $storage) ? 'storage/'.substr($path, strlen($storage)) : basename($path);
    }
}
