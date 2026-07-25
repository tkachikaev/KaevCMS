# Installation

## Requirements

- PHP 8.3 or newer.
- MySQL or MariaDB with a dedicated empty database.
- PHP extensions: PDO, `pdo_mysql`, mbstring, fileinfo, DOM, OpenSSL, tokenizer, ctype, JSON, and session.
- HTTPS for a production website.
- Writable `storage`, `bootstrap/cache`, and public `uploads` directories.

KaevCMS public entry points display a readable bilingual error on old PHP versions before Laravel or the Web Installer is loaded.

## VDS or configurable Document Root

For a complete Ubuntu 24.04 LTS, nginx, PHP 8.3-FPM, MySQL, HTTPS, permissions, scheduler, and queue-worker walkthrough, see [Ubuntu VDS installation](VDS_UBUNTU.md).


1. Extract the full release outside the web root.
2. Point the domain Document Root to the release `public/` directory.
3. Install production dependencies:

```bash
composer install --no-dev --optimize-autoloader
```

4. Open `/install/` through the available address. Over HTTP the installer shows a warning but still allows the database check and installation. Configure HTTPS before opening a public website to users.
5. Use a new empty database. The installer refuses to reuse an existing KaevCMS owner account.
6. After a successful installation, remove the public `/install` directory, then review the final security report before opening the website to users.

## Windows development installation

```powershell
.\deployment\windows\setup.ps1
.\deployment\windows\quality.ps1
.\deployment\windows\browser-quality.ps1
```

`setup.ps1` creates the local `.env`, application key, runtime directories, and development dependencies. It is not a shared-hosting deployment script.

## Changing the address after installation

The primary site address is stored in `.env` as `APP_URL`. For HTTP, forced HTTPS and secure-only cookies must also be disabled:

```env
APP_URL=http://192.168.50.111
APP_FORCE_HTTPS=false
SESSION_SECURE_COOKIE=false
```

For HTTPS:

```env
APP_URL=https://cms.example.com
APP_FORCE_HTTPS=true
SESSION_SECURE_COOKIE=true
```

After changing the values, run:

```bash
php artisan optimize:clear
```

On a VDS, also change `server_name` in `/etc/nginx/sites-available/kaevcms`, then run `sudo nginx -t` and `sudo systemctl reload nginx`. Detailed steps and the `419 Page Expired` fix are documented in the [Ubuntu VDS guide](VDS_UBUNTU.md#changing-the-site-address-after-installation).

## After installation

- Sign in as the owner.
- Configure LoginServer and GameServer connections.
- Configure SMTP and test mail delivery.
- Review scheduler and queue diagnostics.
- Keep `APP_DEBUG=false` in production.
- Remove old release archives from public storage after extraction.
