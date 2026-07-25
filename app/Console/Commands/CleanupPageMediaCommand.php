<?php

namespace App\Console\Commands;

use App\Services\Pages\PageImageStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CleanupPageMediaCommand extends Command
{
    protected $signature = 'kaevcms:page-media-clean
        {--hours=24 : Keep unreferenced files newer than this many hours}
        {--dry-run : Show what would be removed without deleting files}';

    protected $aliases = ['l2forge:page-media-clean'];

    protected $description = 'Remove old unreferenced content images from page uploads';

    public function handle(PageImageStorage $storage): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');
        $pagesRoot = $storage->rootPath().DIRECTORY_SEPARATOR.'pages';

        if (! File::isDirectory($pagesRoot)) {
            $this->info('Page upload directory does not exist. Nothing to clean.');

            return self::SUCCESS;
        }

        $cutoff = now()->subHours($hours)->getTimestamp();
        $removed = 0;
        $kept = 0;

        $files = array_map(
            static fn ($file): string => $file->getPathname(),
            File::allFiles($pagesRoot)
        );

        foreach ($files as $absolutePath) {
            if (! File::isFile($absolutePath)) {
                continue;
            }

            $relativePath = ltrim(substr($absolutePath, strlen($pagesRoot)), '\\/');
            $relative = 'pages/'.str_replace('\\', '/', $relativePath);
            $normalized = $storage->normalizePath($relative);
            $modifiedAt = @filemtime($absolutePath);

            if ($modifiedAt === false) {
                $kept++;

                continue;
            }

            $isOldEnough = $modifiedAt <= $cutoff;

            if ($normalized === null || ! $isOldEnough || $storage->isReferenced($normalized)) {
                $kept++;

                continue;
            }

            $this->line(($dryRun ? '[dry-run] ' : '').'remove '.$normalized);

            if ($dryRun) {
                $removed++;

                continue;
            }

            if ($storage->deleteIfUnreferenced($normalized)) {
                $removed++;
            } else {
                $kept++;
            }
        }

        $this->newLine();
        $this->info(($dryRun ? 'Would remove' : 'Removed')." {$removed} file(s); kept {$kept} file(s).");

        return self::SUCCESS;
    }
}
