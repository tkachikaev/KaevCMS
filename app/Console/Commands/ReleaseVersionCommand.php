<?php

namespace App\Console\Commands;

use App\Services\Releases\InstalledVersion;
use App\Support\KaevCMS;
use Illuminate\Console\Command;
use Throwable;

class ReleaseVersionCommand extends Command
{
    protected $signature = 'kaevcms:release-version {--mark= : Persist the successfully installed release version}';

    protected $description = 'Read or record the installed KaevCMS release version';

    public function handle(InstalledVersion $installedVersion): int
    {
        $mark = $this->option('mark');
        if (is_string($mark) && trim($mark) !== '') {
            $mark = trim($mark);
            $releaseVersion = KaevCMS::version();

            if ($mark !== $releaseVersion) {
                $this->error("Refusing to record {$mark}; the extracted release is {$releaseVersion}.");

                return self::FAILURE;
            }

            try {
                $installedVersion->mark($mark);
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            $this->info("Installed version recorded: {$mark}");

            return self::SUCCESS;
        }

        try {
            $current = $installedVersion->current();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($current === null) {
            $this->warn('Installed version is not recorded.');

            return self::FAILURE;
        }

        $this->line($current);

        return self::SUCCESS;
    }
}
