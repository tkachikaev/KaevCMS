# VDS deployment / Установка на VDS

## English

The maintained baseline is Ubuntu Server 24.04 LTS with nginx, PHP 8.3-FPM, MySQL, and Composer. Point nginx only to `public/`; keep `.env`, `vendor`, storage, logs, and application code outside the Document Root. For browser-based VDS updates, install the local agent once with `sudo bash deployment/vds/install-update-agent.sh`; it runs the existing updater as the deployment owner without granting `www-data` source-code write access. `php artisan kaevcms:update` remains the SSH fallback.

- [Complete Ubuntu VDS guide](../../docs/en/VDS_UBUNTU.md)
- [Installation overview](../../docs/en/INSTALLATION.md)
- [Security and permissions](../../docs/en/SECURITY.md)
- [Mail, scheduler, and queues](../../docs/en/MAIL_AND_QUEUES.md)

## Русский

Поддерживаемая базовая конфигурация — Ubuntu Server 24.04 LTS, nginx, PHP 8.3-FPM, MySQL и Composer. Направляйте nginx только на `public/`; `.env`, `vendor`, runtime-каталоги, журналы и код приложения должны оставаться вне Document Root. Для обновлений VDS через браузер один раз установите локальный агент командой `sudo bash deployment/vds/install-update-agent.sh`: он запускает существующий updater от владельца проекта без выдачи `www-data` прав на исходный код. `php artisan kaevcms:update` остаётся резервным SSH-способом.

- [Полная инструкция для Ubuntu VDS](../../docs/ru/VDS_UBUNTU.md)
- [Обзор установки](../../docs/ru/INSTALLATION.md)
- [Безопасность и права](../../docs/ru/SECURITY.md)
- [Почта, планировщик и очереди](../../docs/ru/MAIL_AND_QUEUES.md)
