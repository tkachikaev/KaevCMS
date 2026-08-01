<?php

namespace App\Console\Commands;

use App\Services\Notifications\AdminNotificationSourceScanner;
use Illuminate\Console\Command;

final class ScanAdminNotificationsCommand extends Command
{
    protected $signature = 'kaevcms:notifications-scan';

    protected $description = 'Create deduplicated administrator notifications for actionable CMS problems';

    public function handle(AdminNotificationSourceScanner $scanner): int
    {
        $results = $scanner->scan();
        $created = array_sum($results);

        $this->info(__('Administrator notifications scanned. New notifications: :count.', [
            'count' => $created,
        ]));

        return self::SUCCESS;
    }
}
