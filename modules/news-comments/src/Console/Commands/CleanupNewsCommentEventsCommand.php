<?php

namespace KaevCMS\Modules\NewsComments\Console\Commands;

use App\Support\Database\TableExistence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class CleanupNewsCommentEventsCommand extends Command
{
    protected $signature = 'kaevcms:news-comments-clean-events {--batch=1000}';

    protected $description = 'Remove expired news comment submission records';

    public function handle(): int
    {
        if (! app(TableExistence::class)->has('module_news_comment_submission_events')) {
            return self::SUCCESS;
        }

        // The active 24-hour rolling window is evaluated by created_at, not by this job.
        // Keep an extra day to tolerate delayed scheduler execution on shared hosting.
        $batch = max(1, min(5000, (int) $this->option('batch')));
        $ids = DB::table('module_news_comment_submission_events')
            ->where('created_at', '<', now()->subHours(48))
            ->orderBy('id')
            ->limit($batch)
            ->pluck('id')
            ->all();

        if ($ids !== []) {
            DB::table('module_news_comment_submission_events')->whereIn('id', $ids)->delete();
        }

        $this->info('Expired submission records removed: '.count($ids));

        return self::SUCCESS;
    }
}
