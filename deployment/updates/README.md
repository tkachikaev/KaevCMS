# Web Update packages / Пакеты Web Update

## English

A cumulative Web Update ZIP contains `kaevcms-update.json` at the archive root and payload files under `payload/core/` and `payload/public/`.

- `core/` targets the private application root.
- `public/` targets the active public path in standard and split layouts.
- `.env`, storage, SQLite runtime files, user uploads, split-path runtime configuration, caches, and development dependencies are excluded.
- Every payload file has a SHA256 hash.
- Composer lock changes are rejected by Web Update and require a full deployment.
- The current cumulative baseline is `0.42.4`.

Use the unified release builder for official releases:

```powershell
.\deployment\windows\build-release.ps1 `
    -PreviousFullArchive "C:\Releases\KaevCMS-0.42.4-full.zip" `
    -OutputDirectory "C:\Releases\0.43.0"
```

`deployment/updates/build-package.php` remains the lower-level cumulative-package component used by that command. `deletions.json` stores versioned deletion history; removed paths must be declared before a release can be built.

## Русский

Cumulative Web Update ZIP содержит `kaevcms-update.json` в корне и файлы в `payload/core/` и `payload/public/`.

- `core/` применяется к закрытому корню приложения.
- `public/` применяется к активному публичному каталогу standard- или split-схемы.
- `.env`, storage, runtime SQLite, пользовательские uploads, runtime-конфигурация split-пути, кэши и dev-зависимости исключаются.
- Для каждого payload-файла записывается SHA256.
- Изменение `composer.lock` блокирует Web Update и требует полного развёртывания.
- Текущая cumulative-база — `0.42.4`.

Для официального релиза используйте единый сборщик:

```powershell
.\deployment\windows\build-release.ps1 `
    -PreviousFullArchive "C:\Releases\KaevCMS-0.42.4-full.zip" `
    -OutputDirectory "C:\Releases\0.43.0"
```

`deployment/updates/build-package.php` остаётся низкоуровневой частью сборки cumulative-пакета. `deletions.json` хранит историю удалений по версиям; незаявленное удаление блокирует выпуск.
