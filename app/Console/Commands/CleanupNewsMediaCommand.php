<?php

namespace App\Console\Commands;

use App\Services\News\NewsImageStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CleanupNewsMediaCommand extends Command
{
    protected $signature = 'kaevcms:news-media-clean
        {--hours=24 : Keep unreferenced files newer than this many hours}
        {--dry-run : Show what would be removed without deleting files}';

    protected $aliases = ['l2forge:news-media-clean'];

    protected $description = 'Remove old unreferenced cover and content images from news uploads';

    public function handle(NewsImageStorage $storage): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');
        $newsRoot = $storage->rootPath().DIRECTORY_SEPARATOR.'news';

        if (! File::isDirectory($newsRoot)) {
            $this->info('News upload directory does not exist. Nothing to clean.');

            return self::SUCCESS;
        }

        $cutoff = now()->subHours($hours)->getTimestamp();
        $removed = 0;
        $kept = 0;

        $files = array_map(
            static fn ($file): string => $file->getPathname(),
            File::allFiles($newsRoot)
        );

        foreach ($files as $absolutePath) {
            // Another cleanup operation or a previous iteration may already have
            // removed the file. SplFileInfo::getMTime() throws on Windows in that
            // situation, so work with a stable path and re-check it first.
            if (! File::isFile($absolutePath)) {
                continue;
            }

            $relativePath = ltrim(substr($absolutePath, strlen($newsRoot)), '\\/');
            $relative = 'news/'.str_replace('\\', '/', $relativePath);
            $normalized = $storage->normalizeNewsPath($relative);
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
