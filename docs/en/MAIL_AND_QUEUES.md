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
