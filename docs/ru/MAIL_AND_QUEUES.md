# Почта, планировщик и очереди

KaevCMS поддерживает синхронную отправку, фоновое подключение и очередь в базе данных. Запрошенный асинхронный режим включается только после успешной проверки.

## Настройка асинхронной очереди на Linux VDS

На Ubuntu VDS используйте systemd-службу `kaevcms-queue` из [инструкции Ubuntu VDS, шаг 14](VDS_UBUNTU.md#14-queue-worker-для-асинхронной-почты). Она обрабатывает очереди `mail-probe,mail,default` в этом порядке и нужна только после включения асинхронной отправки.

После каждого обновления перезапустите worker:

```bash
php artisan queue:restart
sudo systemctl restart kaevcms-queue
```

## Планировщик

Запускайте каждую минуту:

```cron
* * * * * cd /var/www/kaevcms && php artisan schedule:run >> /dev/null 2>&1
```

На VDS используйте cron-файл из [инструкции Ubuntu VDS, шаг 13](VDS_UBUNTU.md#13-настройте-планировщик).

На хостинге без постоянного worker команда `kaevcms:queue-drain` обрабатывает очереди `mail-probe,mail,default` в порядке приоритета. Runtime-диагностика показывает heartbeat планировщика, ожидающие задания, ошибки и состояние доставки, не раскрывая payload.
