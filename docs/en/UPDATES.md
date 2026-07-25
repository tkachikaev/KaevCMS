# Updates

KaevCMS is distributed as:

- a complete source release;
- a Windows patch from the previous version;
- a cumulative Update ZIP covering a supported installed-version range.

A single cumulative package may update, for example, `0.32.5` directly to `0.33.0`. Intermediate releases do not need to be installed when the current version is inside the package range.

The release filename states the complete supported source range, for example `KaevCMS-cumulative-update-0.32.0-0.32.20-to-0.33.0.zip`. Every installed version from `0.32.0` through `0.32.20` may update directly to `0.33.0`.


The cumulative archive is validated against the oldest Web Updater included in its declared source range, not only against the newest updater. Clean-install templates and runtime-owned upload control files are excluded when an older updater would reject those targets. Missing upload protection is recreated idempotently by the updated application after installation.

## Package source

Download updates only from the official KaevCMS repository and compare SHA-256 with the published checksum file. The CMS validates the archive and every payload file, while the administrator remains responsible for choosing a trusted download source.

## Before updating

1. Back up `.env`, the CMS database, public uploads, and owner-maintained assets.
2. Compare the archive SHA256 with the published checksum.
3. Obtain packages only from a trusted source. Current packages verify manifest and payload integrity, but do not yet carry a separate publisher cryptographic signature.
4. Do not replace runtime secrets or `storage` with files from a complete archive.

## Shared hosting

Use the Web Updater in the administration panel. It validates the version range, manifest, every payload SHA256, forbidden targets, free disk space, required backups, and recovery state.

The Web Updater requires write access to the installed files. Do not assign `0777` to the whole project merely to pass preflight.

In split/shared-hosting layouts, the actual public directory is read from the generated runtime configuration. Public entrypoints are layout-neutral and `kaevcms-path.php` is protected from package replacement or deletion, so technical-domain and custom directory names remain valid after cumulative updates.

## Ubuntu VDS

On a VDS, source files belong to the SSH/deployment user while PHP-FPM may write only to runtime directories. Apply the package through the CLI as the project owner:

```bash
cd /var/www/kaevcms
php artisan kaevcms:update /tmp/KaevCMS-update.zip
```

The command displays its checks and asks for confirmation. For automation after independently verifying the package:

```bash
php artisan kaevcms:update /tmp/KaevCMS-update.zip --yes
```

Do not run the command as `www-data` and do not grant PHP-FPM write access to all source files.

## Dependency changes

When a release changes `composer.lock`, deploy the complete release and run:

```bash
composer install --no-dev --optimize-autoloader --no-interaction
```

Never run `composer update` on production.

## Windows

After extracting a patch, run the versioned apply script and then:

```powershell
.\deployment\windows\quality.ps1
.\deployment\windows\browser-quality.ps1
```

### Interrupted 0.33.0 Windows update

KaevCMS 0.33.1 recognizes both a completed 0.33.0 installation and the preserved 0.32.20 marker/pending state left when the 0.33.0 automated tests failed. Extract the complete 0.33.0-to-0.33.1 patch and run `deployment\windows\apply-0.33.1.ps1`; do not delete the pending marker or update backups.
