# Mail, scheduler, and queues

KaevCMS supports synchronous delivery, a background process connection, and a database queue. A requested asynchronous mode is enabled only after its probe succeeds.

## Linux VDS worker

On an Ubuntu VDS use the systemd service `kaevcms-queue` described in [Ubuntu VDS, step 14](VDS_UBUNTU.md#14-queue-worker-for-asynchronous-mail). It processes the queues `mail-probe,mail,default` in this order and is needed only after asynchronous delivery is enabled.

After each update, restart the worker:

```bash
php artisan queue:restart
sudo systemctl restart kaevcms-queue
```

## Scheduler

Run every minute:

```cron
* * * * * cd /var/www/kaevcms && php artisan schedule:run >> /dev/null 2>&1
```

On a VDS, use the cron file from [Ubuntu VDS, step 13](VDS_UBUNTU.md#13-configure-the-scheduler).

On hosting without a persistent worker, the scheduler command `kaevcms:queue-drain` processes the database queues `mail-probe,mail,default` in priority order. Runtime diagnostics report scheduler heartbeat, pending jobs, failures, and mail delivery state without exposing payloads.

## Ordinary hosting (Plesk, cPanel, DirectAdmin, ISPmanager)

Open **Settings → Mail → Delivery mode**. The "Cron for the database queue" section at the bottom already holds a ready cron line: it uses the real path to `artisan` (including the separate core directory of a split install) and the PHP 8.3+ binary found on the server. Do not copy the PHP or `artisan` path from hosting support hints without checking: the site needs PHP 8.3+, and `artisan` lives in the core directory, not in the public folder.

1. Copy the cron line (or the short command for panels with a separate schedule field, such as Plesk) and create a job that runs every minute.
2. Press **Check cron**. The check waits for the next real Scheduler run and shows "Working" only if that run happened after the button was pressed.
3. Choose "Asynchronous with database queue" — the test passes as soon as cron processes the test job.

When a mode check fails, the page says why (for example the hosting forbids `proc_open`, no PHP 8.3+ was found, cron never ran or stopped) and what to do. Synchronous delivery stays active, so mail is neither lost nor duplicated. The "Asynchronous" mode needs no cron but depends on the hosting allowing background processes; use the database queue when it does not.


## When the mail provider is unavailable

A queued message keeps trying for 30 minutes: up to five real failures with pauses of 1, 3, 5 and 10 minutes. As soon as the provider does not answer, all queued mail pauses for a minute without using up its tries, so a provider that hangs does not block the other jobs. The provider gets 10 seconds to answer. Messages that are still not sent after 30 minutes stay in failed jobs: retry them on the admin queue page, or the player requests a new link.

Without a persistent worker `kaevcms:queue-drain` sends at most 100 messages per minute. During a sign-up burst mail arrives a few minutes late but is not lost (measurements: `tests/load/RESULTS.md`).
