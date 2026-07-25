<?php

namespace App\Services\Updates;

use RuntimeException;

final class UpdateLock
{
    /** @return resource */
    public function acquire()
    {
        $directory = storage_path('app/kaevcms/updates');
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(__('Unable to create the update lock directory.'));
        }

        $lock = fopen($directory.'/update.lock', 'c+');
        if (! is_resource($lock) || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new RuntimeException(__('Another system update is already running.'));
        }

        ftruncate($lock, 0);
        fwrite($lock, (string) getmypid());
        fflush($lock);

        return $lock;
    }

    /** @param resource $lock */
    public function release($lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
