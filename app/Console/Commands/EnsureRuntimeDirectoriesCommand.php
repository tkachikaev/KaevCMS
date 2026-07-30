<?php

namespace App\Console\Commands;

use App\Services\Infrastructure\RuntimeDirectoryManager;
use Illuminate\Console\Command;
use RuntimeException;

final class EnsureRuntimeDirectoriesCommand extends Command
{
    protected $signature = 'kaevcms:runtime-directories
        {--probe : Verify that nested directories and files can be created}';

    protected $description = 'Create and optionally verify KaevCMS runtime directories';

    public function handle(RuntimeDirectoryManager $runtimeDirectories): int
    {
        try {
            $verified = $runtimeDirectories->ensure((bool) $this->option('probe'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($verified as $relativePath) {
            $this->line(($this->option('probe') ? '[verified] ' : '[created] ').$relativePath);
        }

        return self::SUCCESS;
    }
}
