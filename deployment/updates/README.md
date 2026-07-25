# Web Update packages / Пакеты Web Update

## English

A Web Update ZIP contains `kaevcms-update.json` at the archive root and payload files under `payload/core/` and `payload/public/`.

- `core/` targets the private application root.
- `public/` targets the active public path in standard and split layouts. Public entrypoints are layout-neutral and discover a split core through the generated `kaevcms-path.php`.
- `.env`, `.env.example`, `storage`, SQLite runtime files, user uploads, split-path configuration (`public/kaevcms-path.php` and `bootstrap/kaevcms-public-path.php`), and upload control files are excluded from cumulative payloads while the 0.32.x compatibility range is maintained. The updated application recreates missing upload protection idempotently.
- Every payload file has a SHA256 hash.
- Packages with changed Composer dependencies are rejected and require a full deployment.
- Every target is checked against the oldest Web Updater policy in the declared range.
- The filename must expose the supported source range, for example `KaevCMS-cumulative-update-0.32.0-0.32.20-to-0.33.0.zip`.

Example builder command:

```powershell
php deployment/updates/build-package.php `
    --root="C:\Releases\KaevCMS-0.33.0" `
    --output="C:\Releases\KaevCMS-cumulative-update-0.32.0-0.32.20-to-0.33.0.zip" `
    --minimum=0.32.0 `
    --maximum=0.32.20 `
    --target=0.33.0 `
    --delete-file=deployment/updates/deletions.json `
    --previous-root="C:\Releases\KaevCMS-0.32.20" `
    --update-history
```

## Русский

Web Update ZIP содержит `kaevcms-update.json` в корне и файлы в `payload/core/` и `payload/public/`.

- `core/` применяется к закрытому корню приложения.
- `public/` применяется к активному публичному каталогу в стандартной и split-схеме. Публичные точки входа не зависят от раскладки и находят split-ядро через сгенерированный `kaevcms-path.php`.
- `.env`, `.env.example`, `storage`, runtime SQLite, пользовательские uploads, конфигурация split-пути (`public/kaevcms-path.php` и `bootstrap/kaevcms-public-path.php`) и служебные файлы uploads исключаются из кумулятивного payload, пока поддерживается совместимость с веткой 0.32.x. После обновления приложение безопасно создаёт недостающую защиту uploads.
- Для каждого файла проверяется SHA256.
- Изменение Composer-зависимостей требует полного развёртывания и блокируется Web Updater.
- Каждый target проверяется правилами самого старого Web Updater из заявленного диапазона.
- В имени файла указывайте диапазон исходных версий, например `KaevCMS-cumulative-update-0.32.0-0.32.20-to-0.33.0.zip`.

`deletions.json` хранит историю удалений по версиям. `--previous-root` добавляет пути, которые существовали в предыдущем релизе и отсутствуют в новом. При прерванном обновлении владелец использует сохранённое состояние и резервные копии для восстановления.
