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
        $cutoff = now()->subHours(48);
        $removed = 0;

        // A single small DELETE each day can never catch up with more than
        // one batch of daily traffic. Process bounded batches to avoid lengthy
        // table locks while still clearing accumulated backlog.
        for ($iteration = 0; $iteration < 100; $iteration++) {
            $ids = DB::table('module_news_comment_submission_events')
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($batch)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $removed += DB::table('module_news_comment_submission_events')
                ->whereIn('id', $ids)
                ->where('created_at', '<', $cutoff)
                ->delete();

            if (count($ids) < $batch) {
                break;
            }
        }

        $this->info('Expired submission records removed: '.$removed);

        return self::SUCCESS;
    }
}
