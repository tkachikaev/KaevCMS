# Shared-hosting deployment

Shared-hosting providers use different names for the domain public directory. KaevCMS does not guess it: pass the exact directory name as a build key.

## Build a production package

Default public directory `public_html`:

```powershell
.\deployment\windows\build-shared-hosting-package.ps1
```

Custom public directory:

```powershell
.\deployment\windows\build-shared-hosting-package.ps1 `
    -PublicDirectoryName example.hosting.test
```

Custom private core directory and output directory:

```powershell
.\deployment\windows\build-shared-hosting-package.ps1 `
    -PublicDirectoryName public_html `
    -CoreDirectoryName private-kaevcms `
    -OutputDirectory D:\Releases
```

The wrapper removes the copied `vendor` directory and rebuilds a clean production-only dependency tree with `composer install --no-dev --optimize-autoloader`. It then creates a portable ZIP with forward-slash entries. `-IncludeDevelopmentDependencies` is available only for temporary diagnostics and should not be used for a public deployment.

Runtime data from `public/uploads`, `public/storage`, and `public/hot` is excluded. The archive receives a clean `uploads` directory with a defensive `.htaccess` that blocks PHP-like executable files. A package may therefore be built from an already installed test copy without carrying user images into the release.

## Provider examples

### Beget and common cPanel hosting

The site directory usually contains `public_html`. Use the default command and extract the ZIP into the parent site directory:

```text
site-root/
├── kaevcms-core/
└── public_html/
```

Do not extract the whole package inside `public_html`.

### Jino

A technical domain may itself be the public directory name. Build with that exact name:

```powershell
.\deployment\windows\build-shared-hosting-package.ps1 `
    -PublicDirectoryName a860dbbcc70b.hosting.myjino.ru
```

Extract into the directory where the hosting panel expects that domain directory and the private core to be siblings.

### Unknown provider

Open the domain settings and find `Document Root`, `Website root`, `Public directory`, or `Working directory`. Use only the final directory name as `-PublicDirectoryName`. After extraction, `index.php` must be directly inside that public directory.

## Expected package layout

```text
parent-directory/
├── kaevcms-core/
│   ├── app/
│   ├── bootstrap/
│   ├── storage/
│   └── vendor/
└── public_html-or-domain-directory/
    ├── index.php
    ├── .htaccess
    ├── kaevcms-path.php
    ├── install/
    └── uploads/
```

The domain must never point to `kaevcms-core`.


## HTTPS during installation

Web Installer allows installation over both HTTPS and HTTP. On an unencrypted connection it shows one visible warning but does not block the MySQL check, owner creation, or final installation.

For a test environment, local IP, or private network, continue at `http://.../install/`. Before opening a public website to users, configure an SSL certificate and switch to `https://`.

`X-Forwarded-Proto` is accepted only from a local or private reverse proxy.

## Changing the domain or protocol after installation

Open `.env` in the private core directory and update `APP_URL`, `APP_FORCE_HTTPS`, and `SESSION_SECURE_COOKIE`.

For HTTP:

```env
APP_URL=http://example.hosting.test
APP_FORCE_HTTPS=false
SESSION_SECURE_COOKIE=false
```

For HTTPS:

```env
APP_URL=https://example.com
APP_FORCE_HTTPS=true
SESSION_SECURE_COOKIE=true
```

Clear the Laravel cache through the hosting terminal:

```bash
php artisan optimize:clear
```

When no terminal is available, use the command-line feature provided by the hosting panel or contact support. Remove old browser cookies after changing the domain or protocol. `419 Page Expired` during HTTP sign-in usually means `SESSION_SECURE_COOKIE` is still enabled.

## Updating

Use the cumulative Web Update from the administrator panel on shared hosting. One current package upgrades any source version listed as supported in its manifest; intermediate archives are not required.

The supported version range is meaningful only when the package is accepted by the oldest Web Updater in that range. Release validation must include a direct staging test from the oldest retained shared-hosting baseline.


### Why an arbitrary public-directory name is safe

The package builder records both directions of the split layout:

- `<public directory>/kaevcms-path.php` points the web entrypoints to the private core;
- `<core>/bootstrap/kaevcms-public-path.php` tells Laravel and CLI update commands the actual public directory.

The public directory may therefore be named `public_html`, `htdocs`, a technical domain such as `a860dbbcc70b.hosting.myjino.ru`, or another provider-specific value. Web Update resolves `public/...` targets through Laravel's active `public_path()` and never guesses or hardcodes the directory name.

The standard and shared-hosting copies of `index.php`, `install/index.php`, and `.htaccess` are intentionally identical. They detect `kaevcms-path.php` at runtime, so a cumulative update cannot replace split entrypoints with an incompatible standard variant. The updater is forbidden from replacing or deleting `kaevcms-path.php` itself.
