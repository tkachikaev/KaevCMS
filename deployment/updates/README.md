# Web Update packages / Пакеты Web Update

## English

A cumulative Web Update ZIP contains `kaevcms-update.json` at the archive root and payload files under `payload/core/` and `payload/public/`.

- `core/` targets the private application root.
- `public/` targets the active public path in standard and split layouts.
- `.env`, storage, SQLite runtime files, user uploads, split-path runtime configuration, caches, and development dependencies are excluded.
- Every payload file has a SHA256 hash.
- Since 0.51.0 the cumulative package carries the production dependencies (`vendor`) built from the release's `composer.lock`, so Web Update can cross Composer dependency changes. See [Dependency changes](../../docs/en/UPDATES.md#dependency-changes). Deploying the full release and running `composer install --no-dev` remains only a manual fallback.
- Sites on 0.42.4 through 0.50.16 install the bridge package (`KaevCMS-bridge-update-0.42.4-0.50.16-for-<version>.zip`) first.
- The current cumulative baseline is `0.42.4`.

Use the unified release builder for official releases:

```powershell
.\deployment\windows\build-release.ps1 `
    -PreviousFullArchive "C:\Releases\KaevCMS-<previous>-full.zip" `
    -OutputDirectory "C:\Releases\<version>"
```

`deployment/updates/build-package.php` remains the lower-level cumulative-package component used by that command. `deletions.json` stores versioned deletion history; removed paths must be declared before a release can be built.

## Русский

Cumulative Web Update ZIP содержит `kaevcms-update.json` в корне и файлы в `payload/core/` и `payload/public/`.

- `core/` применяется к закрытому корню приложения.
- `public/` применяется к активному публичному каталогу standard- или split-схемы.
- `.env`, storage, runtime SQLite, пользовательские uploads, runtime-конфигурация split-пути, кэши и dev-зависимости исключаются.
- Для каждого payload-файла записывается SHA256.
- Начиная с 0.51.0 cumulative-пакет содержит production-библиотеки (`vendor`), собранные по `composer.lock` релиза, поэтому Web Update проходит и через изменение Composer-зависимостей. Подробнее в разделе [Изменение зависимостей](../../docs/ru/UPDATES.md#изменение-зависимостей). Полный релиз и `composer install --no-dev` остаются только ручным запасным вариантом.
- Сайты на версиях с 0.42.4 по 0.50.16 сначала устанавливают пакет-мост (`KaevCMS-bridge-update-0.42.4-0.50.16-for-<версия>.zip`).
- Текущая cumulative-база — `0.42.4`.

Для официального релиза используйте единый сборщик:

```powershell
.\deployment\windows\build-release.ps1 `
    -PreviousFullArchive "C:\Releases\KaevCMS-<предыдущая>-full.zip" `
    -OutputDirectory "C:\Releases\<версия>"
```

`deployment/updates/build-package.php` остаётся низкоуровневой частью сборки cumulative-пакета. `deletions.json` хранит историю удалений по версиям; незаявленное удаление блокирует выпуск.
