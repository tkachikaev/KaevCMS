# Hosting deployment / Развёртывание на хостинге

## English

KaevCMS supports two secure layouts:

1. Standard: the full Laravel project is private and the domain Document Root points to `public/`.
2. Split shared hosting: a generated private core and a provider-specific public directory are siblings.

For shared hosting use:

```powershell
.\deployment\windows\build-shared-hosting-package.ps1 `
    -PublicDirectoryName public_html
```

Use the exact directory name shown in the provider control panel. Jino may use a domain-name directory; Beget commonly uses `public_html`.

The Web Installer blocks a domain that exposes the project root through `/public/`, checks PHP 8.3+, extensions, paths, permissions, and database privileges, shows a visible warning on HTTP without blocking installation, recommends HTTPS for public websites, and shows a final security review.

See `docs/en/INSTALLATION.md`, `docs/en/SHARED_HOSTING.md`, and `docs/en/SECURITY.md`.

## Русский

KaevCMS поддерживает две безопасные схемы:

1. Стандартная: весь Laravel-проект закрыт, а Document Root домена направлен на `public/`.
2. Split shared-hosting: подготовленное закрытое ядро и публичный каталог провайдера находятся рядом.

Для обычного хостинга используйте:

```powershell
.\deployment\windows\build-shared-hosting-package.ps1 `
    -PublicDirectoryName public_html
```

Передавайте точное имя каталога из панели хостинга. На Jino это может быть папка с именем домена, на Beget обычно используется `public_html`.

Web Installer блокирует схему, где корень проекта открыт через `/public/`, проверяет PHP 8.3+, расширения, пути, права и доступ пользователя базы, показывает заметное предупреждение при работе по HTTP, не блокирует установку, рекомендует HTTPS для публичного сайта, а после установки показывает отчёт безопасности.

См. `docs/ru/INSTALLATION.md`, `docs/ru/SHARED_HOSTING.md` и `docs/ru/SECURITY.md`.
