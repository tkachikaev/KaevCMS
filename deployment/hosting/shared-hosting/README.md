# Shared-hosting package / Пакет для обычного хостинга

## English

The package contains a private core, a public directory with the exact name passed through `-PublicDirectoryName`, and `INSTALL-SHARED-HOSTING.txt`. Extract it into the parent directory, not inside the public directory.

The Windows wrapper rebuilds `vendor` from an empty directory with production Composer dependencies, excludes existing `public/uploads`, `public/storage`, and `public/hot`, and uses the PHP ZIP implementation so Linux hosting receives portable `/` entry names.

## Русский

Пакет содержит закрытое ядро, публичный каталог с точным именем из `-PublicDirectoryName` и `INSTALL-SHARED-HOSTING.txt`. Распаковывайте его в родительский каталог, а не внутрь публичной папки.

Windows-скрипт пересобирает `vendor` с нуля только с production-зависимостями, исключает существующие `public/uploads`, `public/storage` и `public/hot`, а также использует PHP ZIP, чтобы Linux-хостинг получил переносимые пути с `/`.
