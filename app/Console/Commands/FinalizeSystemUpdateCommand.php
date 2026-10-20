<?php

namespace App\Console\Commands;

use App\Models\SystemUpdate;
use App\Services\Updates\SystemUpdateInstaller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Throwable;

/**
 * Finishes an update whose Composer dependencies were already replaced. It must run in a
 * PHP process started after that swap; the VDS agent starts it automatically.
 */
final class FinalizeSystemUpdateCommand extends Command
{
    protected $signature = 'kaevcms:update-finalize {uuid : Identifier of the update waiting to be finalized}';

    protected $description = 'Finish a KaevCMS update after its Composer dependencies were replaced';

    public function handle(SystemUpdateInstaller $installer): int
    {
        App::setLocale('en');

        $uuid = strtolower((string) $this->argument('uuid'));
        if (! Str::isUuid($uuid)) {
            $this->error('The update identifier is invalid.');

            return self::FAILURE;
        }

        $update = SystemUpdate::query()->where('uuid', $uuid)->first();
        if (! $update instanceof SystemUpdate || ! $update->awaitsFinalization()) {
            $this->error('No update with this identifier is waiting to be finalized.');

            return self::FAILURE;
        }

        if (! $this->runsAsProjectOwner()) {
            $this->error('Run this command as the owner of the KaevCMS project files, for example: sudo -u <owner> php artisan kaevcms:update-finalize '.$uuid);

            return self::FAILURE;
        }

        try {
            $installer->finalize($update);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("KaevCMS {$update->target_version} installed successfully.");

        return self::SUCCESS;
    }

    private function runsAsProjectOwner(): bool
    {
        if (PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_geteuid')) {
            return true;
        }

        $owner = @fileowner(base_path('artisan'));

        return ! is_int($owner) || posix_geteuid() === $owner;
    }
}
