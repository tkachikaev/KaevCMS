# KaevCMS 0.39.2

[English](#english) · [Русский](#русский)

## English

KaevCMS is an open-source Laravel CMS for Lineage II servers. It provides a public website, a player account, an administration panel, LoginServer/GameServer connections, public statistics, a web reward inventory, trusted modules, themes, localization, mail delivery, runtime diagnostics, cumulative shared-hosting Web Updates, and a VDS CLI updater.

Version 0.39.2 is a quality-gate hotfix for reliable game-account creation. It corrects PHPStan relation handling in the recovery command without changing its behavior.

### Requirements

- PHP 8.3 or newer.
- MySQL or MariaDB.
- PHP extensions required by Laravel and the Web Installer: PDO, `pdo_mysql`, mbstring, fileinfo, DOM, OpenSSL, tokenizer, ctype, JSON, and session.
- HTTPS for production.
- A configurable Document Root or the generated split shared-hosting package.

Public entry points show a readable Russian/English PHP-version page on unsupported runtimes instead of a blank parse error.

### Installation

For a VDS or hosting with a configurable Document Root, point the domain to `public/` and open `/install/`. After the installation succeeds, remove the public `/install` directory.

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
- [Shared hosting](docs/en/SHARED_HOSTING.md)
- [Security and permissions](docs/en/SECURITY.md)
- [Operations runbook](docs/en/OPERATIONS.md)

### Security model

Only the public directory belongs in the web root. `.env`, application code, `vendor`, logs, backups, and installer state remain in the private core. The Web Installer checks the deployment layout, required extensions, write access, database privileges, existing administrators, owner-password verification, and a final post-install security report.

Do not recursively assign `0777`. Typical permissions and hosting caveats are documented in [Security and permissions](docs/en/SECURITY.md).

### Main capabilities

- Separate public and account themes, including Kaev Aurelia Account.
- Persistent Livewire navigation for administration and player account pages.
- Multilingual news, pages, settings, mail templates, and localized routes.
- Owner, administrator, and editor roles; two-factor authentication and recovery codes.
- Encrypted infrastructure credentials and redacted audit logs.
- One L2JMobius game driver with compatible schema profiles.
- Player game-account creation and password management.
- Public game statistics with caching and failure cooldowns.
- Server-bound web inventory and neutral `kaev_reward_queue` delivery.
- Trusted modules with strict manifests and immutable migration tracking.
- Bundled promo-code and monthly Daily Rewards modules.
- Versioned chronicle item catalogs with localized manual overrides and one owner-managed icon pool under `public/uploads/game-assets`.
- Cumulative Web Updater for shared hosting and a deployment-user CLI updater for VDS, with hashes, backups, recovery, and path policy.

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

KaevCMS — открытая CMS на Laravel для серверов Lineage II. Она включает публичный сайт, личный кабинет игрока, административную панель, подключения LoginServer/GameServer, публичную статистику, веб-инвентарь наград, доверенные модули, шаблоны, локализацию, почту, runtime-диагностику, кумулятивные Web Updates для shared-hosting и CLI-обновление для VDS.

Версия 0.38.2 — исправляющее обновление тестового контракта Foundation Hardening. Оно проверяет накопленные удаления релизов, не требуя повторять исторические пути в записи каждой новой версии.

### Требования

- PHP 8.3 или новее.
- MySQL или MariaDB.
- Расширения PHP: PDO, `pdo_mysql`, mbstring, fileinfo, DOM, OpenSSL, tokenizer, ctype, JSON и session.
- HTTPS для рабочего сайта.
- Настраиваемый Document Root или подготовленный split-пакет для обычного хостинга.

На неподдерживаемом PHP публичные точки входа показывают понятную русско-английскую страницу вместо пустой ошибки синтаксиса.

### Установка

На VDS или хостинге с настраиваемым Document Root направьте домен на `public/` и откройте `/install/`. После успешной установки удалите публичную папку `/install`.

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
- [Обычный хостинг](docs/ru/SHARED_HOSTING.md)
- [Безопасность и права](docs/ru/SECURITY.md)
- [Эксплуатационные инструкции](docs/ru/OPERATIONS.md)

### Модель безопасности

В web-root должен находиться только публичный каталог. `.env`, код приложения, `vendor`, журналы, резервные копии и состояние установщика остаются в закрытом ядре. Web Installer проверяет структуру, расширения, запись, права пользователя базы, существующих администраторов, фактический пароль созданного владельца и итоговое состояние безопасности.

Не назначайте `0777` рекурсивно всему проекту. Типовые права и особенности хостингов описаны в [инструкции по безопасности](docs/ru/SECURITY.md).

### Основные возможности

- Раздельные публичные шаблоны и шаблоны личного кабинета, включая Kaev Aurelia Account.
- Постоянная Livewire-навигация в административной панели и кабинете игрока.
- Многоязычные новости, страницы, настройки, почтовые шаблоны и маршруты.
- Роли владельца, администратора и редактора; двухфакторная защита и recovery codes.
- Шифрование инфраструктурных реквизитов и очистка аудита от секретов.
- Один драйвер L2JMobius с совместимыми профилями схем.
- Создание игровых аккаунтов и изменение игровых паролей.
- Публичная статистика с кешем и cooldown при сбоях.
- Веб-инвентарь с привязкой к GameServer и нейтральной `kaev_reward_queue`.
- Доверенные модули со строгим manifest и неизменяемой историей миграций.
- Встроенные модули промокодов и месячных ежедневных наград.
- Версионируемые каталоги предметов по хроникам с ручными переводами и единым внешним пулом иконок под исходными именами.
- Кумулятивный Web Updater для shared-hosting и CLI updater от deployment-пользователя для VDS с хешами, резервными копиями, восстановлением и политикой путей.

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
