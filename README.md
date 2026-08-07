# KaevCMS 0.47.30

[English](#english) · [Русский](#русский)

## English

KaevCMS is an open-source Laravel CMS for Lineage II servers. It provides a public website, a player account, an administration panel, LoginServer/GameServer connections, public statistics, a web reward inventory, trusted modules, themes, localization, mail delivery, runtime diagnostics, cumulative shared-hosting Web Updates, a local VDS update agent, and a VDS CLI updater.

Version 0.47.30 is a quality-gate hotfix for the shared player-account CSS extraction introduced in 0.47.29. Release tests now validate shared component selectors in `public/assets/account/css/components.css` instead of incorrectly requiring them inside each theme-owned `app.css`. Runtime behavior and visual styling are unchanged. Kaev Aurelia Account remains 1.6.6 and L2 Obsidian Luxury remains 1.6.5 with KaevCMS 0.47.29 as their minimum version. Bundled module versions, published migrations, Composer dependencies and VDS update-agent contract 3 are unchanged. The cumulative update baseline remains 0.42.4.

### Requirements

- PHP 8.3 or newer.
- MySQL or MariaDB.
- PHP extensions required by Laravel and the Web Installer: PDO, `pdo_mysql`, mbstring, fileinfo, DOM, OpenSSL, tokenizer, ctype, JSON, and session.
- HTTPS for production.
- A configurable Document Root or the generated split shared-hosting package.

Public entry points show a readable Russian/English PHP-version page on unsupported runtimes instead of a blank parse error.

### Installation

For a VDS or hosting with a configurable Document Root, point the domain to `public/` and open `/install/`. After a successful installation, Web Installer removes the public `/install` directory automatically. If filesystem permissions block deletion, the final page shows the manual fallback. Update packages never restore it and explicitly remove a leftover installer from an installed CMS.

For shared hosting, build a production package on Windows:

```powershell
.\deployment\windows\build-shared-hosting-package.ps1
```

The default public directory is `public_html`. For a provider such as Jino, pass the exact domain-directory name:

```powershell
.\deployment\windows\build-shared-hosting-package.ps1 `
    -PublicDirectoryName example.hosting.test
```

Available keys:

```text
-PublicDirectoryName            public directory name; default: public_html
-CoreDirectoryName              private core name; default: kaevcms-core
-OutputDirectory                output location; default: dist
-IncludeDevelopmentDependencies temporary diagnostics only
```

The default package removes any copied dependency tree, rebuilds a clean `vendor` with `--no-dev --optimize-autoloader`, excludes runtime uploads, and then creates a portable ZIP.

Documentation:

- [Installation](docs/en/INSTALLATION.md)
- [Ubuntu VDS](docs/en/VDS_UBUNTU.md)
- [Updates](docs/en/UPDATES.md)
- [Shared hosting](docs/en/SHARED_HOSTING.md)
- [Character rescue](docs/en/CHARACTER_RESCUE.md)
- [Security and permissions](docs/en/SECURITY.md)
- [Operations runbook](docs/en/OPERATIONS.md)
- [Administration and diagnostic package](docs/en/ADMINISTRATION.md)

### Security model

Only the public directory belongs in the web root. `.env`, application code, `vendor`, logs, backups, and installer state remain in the private core. The Web Installer checks the deployment layout, required extensions, write access, database privileges, existing administrators, owner-password verification, and a final post-install security report.

Do not recursively assign `0777`. Typical permissions and hosting caveats are documented in [Security and permissions](docs/en/SECURITY.md).

### Main capabilities

- Separate public and account themes, including Kaev Aurelia Account.
- Persistent Livewire navigation for administration and player account pages.
- Multilingual news, pages, settings, mail templates, and localized routes.
- Owner, administrator, editor, and trusted Auditor roles; two-factor authentication and recovery codes.
- Encrypted infrastructure credentials, redacted audit logs, a downloadable sanitized diagnostic package, and personal actionable administrator notifications.
- One L2JMobius game driver with compatible schema profiles.
- Player game-account creation, password management, and configurable offline character rescue.
- Public game statistics with caching and failure cooldowns.
- Server-bound web inventory and neutral `kaev_reward_queue` delivery.
- Trusted modules with strict manifests and immutable migration tracking.
- Bundled Promo Codes, Daily Rewards and Support Tickets modules.
- Versioned chronicle item catalogs with localized manual overrides and one owner-managed icon pool under `public/uploads/game-assets`.
- Cumulative Web Updater for shared hosting, a local VDS update agent for browser-started deployments, and a deployment-user CLI fallback, with hashes, backups, recovery, and path policy.

### Development and quality

```powershell
.\deployment\windows\setup.ps1
.\deployment\windows\quality.ps1
.\deployment\windows\browser-quality.ps1
```

The full release intentionally excludes `vendor`. The generated shared-hosting package includes a production-only `vendor` prepared from the local lock file.

See [Development and quality](docs/en/DEVELOPMENT.md).

---

## Русский

KaevCMS — открытая CMS на Laravel для серверов Lineage II. Она включает публичный сайт, личный кабинет игрока, административную панель, подключения LoginServer/GameServer, публичную статистику, веб-инвентарь наград, доверенные модули, шаблоны, локализацию, почту, runtime-диагностику, кумулятивные Web Updates для shared-hosting, локальный агент обновлений VDS и резервное CLI-обновление.

Версия 0.47.30 — hotfix quality-gate после выноса общего CSS личного кабинета в 0.47.29. Release-тесты теперь проверяют общие селекторы в `public/assets/account/css/components.css`, а не требуют их наличия внутри `app.css` каждой темы. Runtime-поведение и внешний вид не меняются. Kaev Aurelia Account остаётся 1.6.6, L2 Obsidian Luxury — 1.6.5; минимальная версия CMS для этих тем остаётся 0.47.29. Версии модулей, опубликованные миграции, Composer-зависимости и контракт VDS update-agent 3 не менялись. Базовая версия cumulative update остаётся 0.42.4.

### Требования

- PHP 8.3 или новее.
- MySQL или MariaDB.
- Расширения PHP: PDO, `pdo_mysql`, mbstring, fileinfo, DOM, OpenSSL, tokenizer, ctype, JSON и session.
- HTTPS для рабочего сайта.
- Настраиваемый Document Root или подготовленный split-пакет для обычного хостинга.

На неподдерживаемом PHP публичные точки входа показывают понятную русско-английскую страницу вместо пустой ошибки синтаксиса.

### Установка

На VDS или хостинге с настраиваемым Document Root направьте домен на `public/` и откройте `/install/`. После успешной установки Web Installer автоматически удаляет публичную папку `/install`. Если права файловой системы не позволяют удалить её, итоговая страница показывает ручное действие. Пакеты обновления больше не восстанавливают installer и удаляют его остатки из уже развёрнутой CMS.

Для обычного хостинга соберите production-пакет в Windows:

```powershell
.\deployment\windows\build-shared-hosting-package.ps1
```

По умолчанию публичная папка называется `public_html`. Для Jino и похожих хостингов передайте точное имя каталога домена:

```powershell
.\deployment\windows\build-shared-hosting-package.ps1 `
    -PublicDirectoryName example.hosting.test
```

Доступные ключи:

```text
-PublicDirectoryName            имя публичного каталога; по умолчанию public_html
-CoreDirectoryName              имя закрытого ядра; по умолчанию kaevcms-core
-OutputDirectory                каталог результата; по умолчанию dist
-IncludeDevelopmentDependencies только временная диагностика
```

Обычная сборка удаляет скопированные зависимости, заново создаёт чистый `vendor` через `--no-dev --optimize-autoloader`, исключает рабочие uploads и затем формирует переносимый ZIP.

Документация:

- [Установка](docs/ru/INSTALLATION.md)
- [Ubuntu VDS](docs/ru/VDS_UBUNTU.md)
- [Обновления](docs/ru/UPDATES.md)
- [Обычный хостинг](docs/ru/SHARED_HOSTING.md)
- [Возврат персонажа в город](docs/ru/CHARACTER_RESCUE.md)
- [Безопасность и права](docs/ru/SECURITY.md)
- [Эксплуатационные инструкции](docs/ru/OPERATIONS.md)
- [Администрирование и диагностический пакет](docs/ru/ADMINISTRATION.md)

### Модель безопасности

В web-root должен находиться только публичный каталог. `.env`, код приложения, `vendor`, журналы, резервные копии и состояние установщика остаются в закрытом ядре. Web Installer проверяет структуру, расширения, запись, права пользователя базы, существующих администраторов, фактический пароль созданного владельца и итоговое состояние безопасности.

Не назначайте `0777` рекурсивно всему проекту. Типовые права и особенности хостингов описаны в [инструкции по безопасности](docs/ru/SECURITY.md).

### Основные возможности

- Раздельные публичные шаблоны и шаблоны личного кабинета, включая Kaev Aurelia Account.
- Постоянная Livewire-навигация в административной панели и кабинете игрока.
- Многоязычные новости, страницы, настройки, почтовые шаблоны и маршруты.
- Роли владельца, администратора, редактора, доверенного аудитора и ограниченной демонстрации; двухфакторная защита и recovery codes.
- Шифрование инфраструктурных реквизитов, очистка аудита от секретов и скачиваемый обезличенный диагностический пакет.
- Один драйвер L2JMobius с совместимыми профилями схем.
- Создание игровых аккаунтов и изменение игровых паролей.
- Публичная статистика с кешем и cooldown при сбоях.
- Веб-инвентарь с привязкой к GameServer и нейтральной `kaev_reward_queue`.
- Доверенные модули со строгим manifest и неизменяемой историей миграций.
- Встроенные модули Promo Codes, Daily Rewards и технической поддержки.
- Версионируемые каталоги предметов по хроникам с ручными переводами и единым внешним пулом иконок под исходными именами.
- Кумулятивный Web Updater для shared-hosting, локальный агент для запуска обновлений VDS из браузера и резервный CLI-способ от deployment-пользователя с хешами, резервными копиями, восстановлением и политикой путей.

### Разработка и проверки

```powershell
.\deployment\windows\setup.ps1
.\deployment\windows\quality.ps1
.\deployment\windows\browser-quality.ps1
```

Полный архив намеренно не содержит `vendor`. Shared-hosting пакет включает production-only `vendor`, подготовленный по локальному lock-файлу.

См. [Разработка и проверки качества](docs/ru/DEVELOPMENT.md).

## License / Лицензия

MIT.
