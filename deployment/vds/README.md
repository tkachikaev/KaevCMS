# VDS deployment / Установка на VDS

## English

The maintained baseline is Ubuntu Server 24.04 LTS with nginx, PHP 8.3-FPM, MySQL, and Composer. Point nginx only to `public/`; keep `.env`, `vendor`, storage, logs, and application code outside the Document Root. For browser-based VDS updates, install the local agent once with `bash deployment/vds/install-update-agent.sh`; it runs the existing updater as the project owner without granting `www-data` source-code write access. The installer requests sudo automatically when needed and supports both root-owned and regular-user-owned projects. In root mode the package is applied with root privileges, so use only trusted archives. `php artisan kaevcms:update` remains the SSH fallback. Installing or reinstalling agent contract v3 also records the deployment UID/web GID, repairs group-readable application files, and repairs the protected Web Update upload and staging directories. Use `--project-user`, `--web-user` and `--web-group` for non-standard ownership.

- [Complete Ubuntu VDS guide](../../docs/en/VDS_UBUNTU.md)
- [Installation overview](../../docs/en/INSTALLATION.md)
- [Security and permissions](../../docs/en/SECURITY.md)
- [Mail, scheduler, and queues](../../docs/en/MAIL_AND_QUEUES.md)

## Русский

Поддерживаемая базовая конфигурация — Ubuntu Server 24.04 LTS, nginx, PHP 8.3-FPM, MySQL и Composer. Направляйте nginx только на `public/`; `.env`, `vendor`, runtime-каталоги, журналы и код приложения должны оставаться вне Document Root. Для обновлений VDS через браузер один раз установите локальный агент командой `bash deployment/vds/install-update-agent.sh`: он запускает существующий updater от владельца проекта без выдачи `www-data` прав на исходный код. Установщик сам запросит sudo при необходимости и поддерживает проекты под root и обычным пользователем. В режиме root пакет применяется с правами root, поэтому используйте только доверенные архивы. `php artisan kaevcms:update` остаётся резервным SSH-способом. Установка или переустановка агента версии 3 также записывает UID владельца и GID веб-группы, восстанавливает чтение файлов приложения и защищённые каталоги Web Update. Для нестандартной схемы используйте `--project-user`, `--web-user` и `--web-group`.

- [Полная инструкция для Ubuntu VDS](../../docs/ru/VDS_UBUNTU.md)
- [Обзор установки](../../docs/ru/INSTALLATION.md)
- [Безопасность и права](../../docs/ru/SECURITY.md)
- [Почта, планировщик и очереди](../../docs/ru/MAIL_AND_QUEUES.md)
