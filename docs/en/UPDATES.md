# Updates

KaevCMS is distributed as:

- a complete source release;
- a Windows patch from the previous version;
- a cumulative Update ZIP covering a supported installed-version range.

The public cumulative line starts at `0.42.4`. The `KaevCMS-cumulative-update-0.42.4-0.44.13-to-0.44.14.zip` package updates supported releases from 0.42.4 through 0.44.13 directly to 0.44.14.


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

If a package exceeds the host's PHP upload limit (often 2–8 MB), raise `upload_max_filesize` and `post_max_size` to 64M or copy the ZIP through FTP into the folder shown in **Package from the server folder** — see [Shared hosting](SHARED_HOSTING.md#upload-limit-and-packages-through-ftp).

In split/shared-hosting layouts, the actual public directory is read from the generated runtime configuration. Public entrypoints are layout-neutral and `kaevcms-path.php` is protected from package replacement or deletion, so technical-domain and custom directory names remain valid after cumulative updates.

## Ubuntu VDS

On a VDS, source files belong to the SSH/deployment user while PHP-FPM may write only to runtime directories.

For the first upgrade from a release older than `0.47.0`, use the manual CLI Updater because older releases do not contain the agent. After installing `0.47.0`, install the agent once and future compatible updates can be started from the panel.

Install the local agent once to start verified packages from the administration panel:

```bash
cd /var/www/kaevcms
bash deployment/vds/install-update-agent.sh
```

The script requests `sudo` when needed, detects the project owner and PHP-FPM identity, repairs permissions, and configures the service. The same command works for root-owned and regular-user-owned projects. Non-standard installations can use `--project-user`, `--web-user`, and `--web-group`.

The Web Updater continues to upload and inspect the ZIP, while installation is delegated to a one-shot systemd agent running as the project owner. When the agent is absent, the page displays the exact command and blocks installation without blocking package upload and verification.

The owner must explicitly confirm trust in the ZIP source. KaevCMS validates the manifest and checksums, but the site owner remains responsible for the selected archive.

Verify or remove the agent with:

```bash
php artisan kaevcms:update-agent:status
sudo bash deployment/vds/remove-update-agent.sh
```

The manual CLI path remains available without the agent:

```bash
cd /var/www/kaevcms
php artisan kaevcms:update /tmp/KaevCMS-update.zip
```

For automation after independently verifying the package:

```bash
php artisan kaevcms:update /tmp/KaevCMS-update.zip --yes
```

Do not run the CLI Updater as `www-data` and do not grant PHP-FPM write access to all source files.

### When an update is stuck

If the server rebooted or the process was killed during an update, the site can stay in maintenance mode and new updates stay blocked. The panel's recovery button runs as PHP-FPM and cannot rewrite program files on a VDS, so use the server-side fallback as the project owner:

```bash
cd /var/www/kaevcms
sudo -u <project-owner> php artisan kaevcms:update-recover
```

The command detects the situation and asks for confirmation:

- an interrupted update — restores the database and files from the verified update backup, records the previous version and lifts maintenance mode;
- a request the agent never started — cancels it and returns the package to the verified state, so it can be started again or discarded from the panel;
- nothing stuck but the site is still in maintenance mode — offers to bring the site back online.

When several updates need attention the command lists them; pass the identifier: `php artisan kaevcms:update-recover <uuid>`. Add `--yes` for scripts. For a root-owned project run it as `root`.

## Dependency changes

From KaevCMS 0.51.0 the cumulative update package carries the production dependencies (`vendor`) built exactly from that release's `composer.lock`. When the site's dependencies differ, the Web Updater and the VDS agent replace them themselves — on a VDS, on shared hosting without SSH or Composer, and on servers without internet access:

1. Before anything changes, the PHP version and extensions required by the new dependencies are checked. If something is missing, the update does not start.
2. The new tree is unpacked beside the live one (`vendor.kaevcms-next`), the CMS files are applied, and the directories are swapped with two renames. The previous tree is kept as `vendor.kaevcms-previous` until the update completes.
3. Migrations and finalisation run in a fresh PHP process that loads only the new dependencies: the VDS agent starts it itself, and in the panel the browser is redirected to the finalisation step automatically.
4. On any failure the database, files and dependencies are restored together.

If the browser was closed between the two steps, open the update page and choose "Finish the update", or run `php artisan kaevcms:update-finalize <uuid>` as the project owner. `php artisan kaevcms:update-recover <uuid>` rolls such an update back instead.

### Sites before 0.51.0: the bridge package

Sites on 0.42.4 through 0.50.16 run their old Web Updater, which cannot replace dependencies and refuses packages with another `composer.lock`. Every release therefore includes a **bridge**, `KaevCMS-bridge-update-…-for-<version>.zip`:

1. Upload the bridge in the panel. It installs the current CMS code and the new updater without touching `composer.lock` or `vendor`, so the old updater accepts it. The panel then shows a version such as `0.51.0-bridge`.
2. Upload the regular cumulative package of the same release right after it. The new updater replaces the dependencies and finishes the update.

The bridge also works on shared hosting without SSH. Sites on 0.51.0 or newer do not need it.

If on MySQL 8 an older release's **System updates** page returns HTTP 500 (`Out of sort memory`, fixed in 0.50.16), first clear the stored manifests of past updates, e.g. in phpMyAdmin, then upload the bridge:

```sql
UPDATE system_updates SET manifest = '{}' WHERE status IN ('succeeded', 'failed', 'discarded');
```

Without the bridge you can deploy the complete release and run:

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
