<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CleanupForumRevisionsCommand extends Command
{
    protected $signature = 'kaevcms:forum-revisions-clean {--batch=1000 : Maximum expired revisions to purge}';

    protected $description = 'Purge expired private forum revisions even while the forum is disabled';

    public function handle(): int
    {
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        if ($batch === false || $batch < 1 || $batch > 10000) {
            $this->error('Batch must be between 1 and 10000.');

            return self::FAILURE;
        }

        // This command is core-owned and continues to run if the forum is disabled.
        if (! Schema::hasTable('module_forum_post_revisions')) {
            return self::SUCCESS;
        }

        $ids = DB::table('module_forum_post_revisions')
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')->orderBy('id')
            ->limit($batch)
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            $deleted = DB::table('module_forum_post_revisions')->whereIn('id', $ids)->delete();
            $this->info('Expired forum revisions deleted: '.$deleted);
        }

        return self::SUCCESS;
    }
}
