# VDS deployment / Установка на VDS

## English

The maintained baseline is Ubuntu Server 24.04 LTS with nginx, PHP 8.3-FPM, MySQL, and Composer. Point nginx only to `public/`; keep `.env`, `vendor`, storage, logs, and application code outside the Document Root. Apply cumulative updates from the terminal with `php artisan kaevcms:update`, running as the deployment owner rather than `www-data`.

- [Complete Ubuntu VDS guide](../../docs/en/VDS_UBUNTU.md)
- [Installation overview](../../docs/en/INSTALLATION.md)
- [Security and permissions](../../docs/en/SECURITY.md)
- [Mail, scheduler, and queues](../../docs/en/MAIL_AND_QUEUES.md)

## Русский

Поддерживаемая базовая конфигурация — Ubuntu Server 24.04 LTS, nginx, PHP 8.3-FPM, MySQL и Composer. Направляйте nginx только на `public/`; `.env`, `vendor`, runtime-каталоги, журналы и код приложения должны оставаться вне Document Root. Кумулятивные обновления применяйте из терминала командой `php artisan kaevcms:update` от владельца deployment, а не от `www-data`.

- [Полная инструкция для Ubuntu VDS](../../docs/ru/VDS_UBUNTU.md)
- [Обзор установки](../../docs/ru/INSTALLATION.md)
- [Безопасность и права](../../docs/ru/SECURITY.md)
- [Почта, планировщик и очереди](../../docs/ru/MAIL_AND_QUEUES.md)
