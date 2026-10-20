<?php

namespace App\Support\Updates;

/**
 * Runs an artisan command in a new PHP CLI process and streams its output.
 *
 * Used after an update swapped vendor/: the current process still holds the previous
 * framework classes, so it must neither boot the console again nor autoload anything from
 * the new tree. This class uses only native PHP and is loaded before the swap.
 */
final class FreshArtisanProcess
{
    /** @param list<string> $arguments */
    public static function run(string $basePath, array $arguments): int
    {
        $process = proc_open(
            [PHP_BINARY, $basePath.DIRECTORY_SEPARATOR.'artisan', ...$arguments],
            [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR],
            $pipes,
            $basePath,
        );
        if (! is_resource($process)) {
            return 1;
        }

        fclose($pipes[0]);

        return proc_close($process);
    }
}
