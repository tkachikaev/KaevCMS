# Updates

KaevCMS is distributed as:

- a complete source release;
- a Windows patch from the previous version;
- a cumulative Update ZIP covering a supported installed-version range.

The public cumulative line starts at `0.42.4`. The `KaevCMS-cumulative-update-0.42.4-0.44.12-to-0.44.13.zip` package updates supported releases from 0.42.4 through 0.44.12 directly to 0.44.13.


The cumulative archive is validated against the oldest Web Updater included in its declared source range, not only against the newest updater. Releases older than `0.42.4` are outside the public update line. Clean-install templates and runtime-owned upload files are excluded from the payload. Missing upload protection is recreated idempotently by the updated application after installation.

## Release contracts

Release numbering and package lineage come from `release.json`; `VERSION` is validated as its compatibility mirror. Windows setup, apply, update, shared-hosting packaging, and Web Update packaging reject inconsistent metadata. Runtime directories and protected environment values are data-driven through `deployment/windows/update-contract.json`, and deletion/recovery lineage is read from `deployment/updates/deletions.json`.

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

For interrupted updates, pending markers, backup handling, and safe recovery steps, see [Operations runbook](OPERATIONS.md).
