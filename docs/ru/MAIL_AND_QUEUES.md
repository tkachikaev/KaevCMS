# Почта, планировщик и очереди

KaevCMS поддерживает синхронную отправку, фоновое подключение и очередь в базе данных. Запрошенный асинхронный режим включается только после успешной проверки.

## Настройка асинхронной очереди на Linux VDS

Создайте `/etc/supervisor/conf.d/kaevcms-mail.conf`:

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

После обновления выполните:

```bash
php artisan queue:restart
```

## Планировщик

Запускайте каждую минуту:

```cron
* * * * * cd /var/www/kaevcms && php artisan schedule:run >> /dev/null 2>&1
```

На хостинге без постоянного worker команда `kaevcms:queue-drain` обрабатывает очереди `mail-probe,mail,default` в порядке приоритета. Runtime-диагностика показывает heartbeat планировщика, ожидающие задания, ошибки и состояние доставки, не раскрывая payload.
