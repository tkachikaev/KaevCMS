<?php

namespace App\Console\Commands;

use App\Services\Notifications\AdminNotificationCenter;
use Illuminate\Console\Command;

final class CleanupAdminNotificationsCommand extends Command
{
    protected $signature = 'kaevcms:notifications-clean {--days= : Override notification retention in days}';

    protected $description = 'Delete administrator notifications older than the configured retention period';

    public function handle(AdminNotificationCenter $notifications): int
    {
        $daysOption = $this->option('days');
        $days = is_numeric($daysOption) ? (int) $daysOption : null;
        $deleted = $notifications->cleanupExpired($days);

        $this->info(__('Old administrator notifications deleted: :count.', [
            'count' => $deleted,
        ]));

        return self::SUCCESS;
    }
}
