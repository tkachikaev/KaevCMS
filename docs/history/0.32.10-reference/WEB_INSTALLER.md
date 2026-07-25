# Web Installer

KaevCMS 0.32.10 supports two safe hosting layouts.

## Standard layout

```text
Document Root -> KaevCMS/public
```

Upload the project with `vendor/`, enable HTTPS, and open the domain. The public entry redirects to `/install/` when `.env` is missing.

## Shared-hosting split layout

When a hosting panel cannot change Document Root, build a split package on Windows:

```text
.\deployment\windows\build-shared-hosting-package.ps1
```

The builder excludes local `.env*` files, SQLite data, logs, sessions, caches, and installation/update locks, then recreates clean writable runtime directories.

The generated archive contains sibling directories:

```text
kaevcms-core/   private Laravel application
public_html/    public website files only
```

The domain must point only to `public_html`. The generated `kaevcms-path.php` connects the public entry to the private core, while `bootstrap/kaevcms-public-path.php` makes Laravel CLI and web operations use the actual public directory.

The internal installer cannot be opened directly through `deployment/hosting/web-installer/installer.php`. Both supported public entries define an explicit installer-entry marker. The requirements page also rejects installations reached through `/public/install/`, because that URL indicates that the project root is exposed above the public directory.

## Installation steps

1. Welcome and language selection.
2. PHP 8.3+, extensions, safe deployment layout, required files, and writable directories.
3. Website URL and MySQL verification, including CREATE, INSERT, ALTER, UPDATE, DELETE, and DROP through a random temporary table. A database that already contains a KaevCMS administrator is rejected.
4. Owner name, email, and password.
5. Atomic `.env` creation, stable `APP_KEY`, migrations, seeding, transactional owner creation, immediate password verification, and release marking.
6. Completion with links to the website and administration panel.

## Security and recovery

- Installer session cookies use `HttpOnly`, `SameSite=Lax`, strict cookie-only sessions, and `Secure` over HTTPS.
- Responses use no-cache, anti-frame, MIME-sniffing, referrer, permissions, and Content Security Policy headers.
- A non-blocking filesystem lock prevents concurrent installation.
- Unexpected details are written to `storage/logs/installer.log` with a short reference code.
- `.env` values are quoted and escaped; an interrupted retry preserves the existing `APP_KEY`.
- `storage/app/installed.lock` blocks reinstallation; `storage/app/installing.lock` allows safe recovery from an incomplete installation.
- Existing administrator rows are never silently reused. The installer requires an empty KaevCMS administrator table and verifies the newly stored owner password before locking the installation.

Standalone regressions:

```text
php deployment/hosting/web-installer/tests/installer-regression.php
php deployment/hosting/shared-hosting/tests/layout-regression.php
php deployment/hosting/shared-hosting/tests/package-builder-regression.php
```
