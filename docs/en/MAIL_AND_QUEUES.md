# Mail, scheduler, and queues

KaevCMS supports synchronous delivery, a background process connection, and a database queue. A requested asynchronous mode is enabled only after its probe succeeds.

## Linux VDS worker

Example Supervisor configuration:

```ini
[program:kaevcms-mail]
command=php /var/www/kaevcms/artisan queue:work database --queue=mail-probe,mail,default --sleep=1 --tries=3 --timeout=120
directory=/var/www/kaevcms
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/kaevcms-mail.log
```

Use the real directive `directory=/var/www/kaevcms` instead of the localized label shown above when copying the file to `/etc/supervisor/conf.d/kaevcms-mail.conf`.

After deployment:

```bash
php artisan queue:restart
```

## Scheduler

Run every minute:

```cron
* * * * * cd /var/www/kaevcms && php artisan schedule:run >> /dev/null 2>&1
```

On hosting without a persistent worker, the scheduler command `kaevcms:queue-drain` processes the configured database queues in priority order. Runtime diagnostics report scheduler heartbeat, pending jobs, failures, and mail delivery state without exposing payloads.
