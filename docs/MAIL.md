# Mail and queues / Почта и очереди

## English

See [Mail, scheduler, and queues](en/MAIL_AND_QUEUES.md).

## Русский

См. [Почта, планировщик и очереди](ru/MAIL_AND_QUEUES.md).

## Настройка асинхронной очереди на Linux VDS

Файл Supervisor: `/etc/supervisor/conf.d/kaevcms-mail.conf`.

Очереди: `mail-probe,mail,default`.

После обновления:

```bash
php artisan queue:restart
```

Планировщик:

```bash
php artisan schedule:run
```

Для shared-hosting используется команда `kaevcms:queue-drain`.
