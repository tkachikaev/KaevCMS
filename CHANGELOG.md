## 0.47.20 - 2026-08-07

### Changed

- Strengthened the unified release builder so target and previous apply-script metadata is verified against the actual source tree and direct previous full archive before any release artifact is accepted.
- Added cross-tree SHA256 validation for both current and previous `composer.lock` metadata, preventing stale dependency hashes from silently entering a release.
- Made `repair_files` data-driven in `ReleaseMetadataTest`: the test now validates structure and file existence instead of hard-coding the current list. The builder rejects repair entries that are already ordinary changed files.
- Added release-builder regression fixtures for mismatched apply hashes, Composer hashes, apply-script metadata and stale repair entries. Application runtime behavior, database migrations, modules, themes and VDS update-agent contract 3 are unchanged.
- The cumulative 0.47.20 package supports updates from 0.42.4 through 0.47.19.

## 0.47.19 - 2026-08-02

### Fixed

- Corrected the system-update progress regression to create and clean up a real temporary log fixture before asserting that the details panel is visible.
- Changed the update status response test to verify the effective `no-store` and `private` cache-control directives after administration middleware normalization instead of requiring one exact header string and order.
- Fixed the remaining Laravel Pint `single_quote` violation in `WebUpdaterReleaseTest`.
- No application runtime file, database migration, Composer dependency, bundled-module version, theme version or VDS update-agent contract change is included. The cumulative 0.47.19 package supports updates from 0.42.4 through 0.47.18.

## 0.47.18 - 2026-08-02

### Fixed

- Removed shared generic HTTP throttles from update upload/apply/recovery and mail delivery-mode probes; duplicate operations are handled by update/probe state instead of returning a raw HTTP 429 page.
- Added an immediate system-update progress dialog, duplicate-submit protection, VDS status polling and automatic state recovery after reloading the update page.
- Collapsed the update log preview behind “Show details” and changed obsolete-path logging to list only paths that were actually removed plus one checked/removed/already-absent summary.
- Forced VDS update-agent register, run and status commands, including status JSON messages, to stable English output without changing localized administration messages or agent contract 3.
- Updated bundled Support Tickets to `1.7.1`; its administrator search field now uses the shared `x-admin.field` design and browser regression coverage.
- No database migration, Composer dependency, bundled-theme version or VDS update-agent contract change is included. The cumulative 0.47.18 package supports updates from 0.42.4 through 0.47.17.

## 0.47.17 - 2026-08-02

### Fixed

- Fixed the Pint `single_quote` failure in `tests/Feature/ReleaseMetadataTest.php` introduced by the Promo Codes archive regression assertions.
- Kept Promo Codes 1.4.0, Support Tickets 1.7.0 and all application runtime behavior unchanged.
- No database migration, Composer dependency, bundled-module version, theme version or VDS update-agent contract change is included. The cumulative 0.47.17 package supports updates from 0.42.4 through 0.47.16.

## 0.47.16 - 2026-08-02

### Added

- Updated bundled Promo Codes to `1.4.0` with separate Current, Archive and All views, archive restoration and explicit archive counts.
- Updated bundled Support Tickets to `1.7.0` with Owner-only deletion of individual closed, unprotected tickets together with their messages, internal notes and revision history.

### Changed

- Promo codes with no activations are now permanently deleted, while used promo codes are disabled and soft-deleted into the archive so activation and granted-reward history remains intact.
- Restored promo codes remain disabled to prevent accidental reactivation and every archive, restore and permanent-delete action is audited.
- Promo-code deletion now locks the row shared with activation processing, preventing a concurrent activation from racing a permanent delete.
- Open or retention-protected support tickets fail closed during manual deletion; Administrator, Editor and Auditor roles do not receive deletion access. Existing scheduled and manual retention cleanup remains unchanged.
- Added positive and negative regressions for promo-code archive/restore, unused-code deletion, ticket deletion cascades, role access, open-ticket rejection and retention-protection rejection.
- No database migration, Composer dependency, bundled-theme version or VDS update-agent contract change is included. The cumulative 0.47.16 package supports updates from 0.42.4 through 0.47.15.

## 0.47.15 - 2026-08-02

### Changed

- Replaced player-facing GameServer queue terminology in Web Inventory with concise transfer wording and the completed status `Transferred` / `Передано`.
- Added explicit validation messages for character, reward selection, server and request-form failures so Laravel no longer exposes internal field names such as `character id` or `inventory item ids`.
- Made unavailable reward delivery fail closed with one generic player message while preserving exact `kaev_reward_queue` diagnostics and remediation in the administration reward journal.
- Updated both bundled account themes to describe reward transfer without mentioning KaevCMS internals or the external queue implementation; Kaev Aurelia Account is now `1.6.5` and L2 Obsidian Luxury is now `1.6.4`.
- Added regressions for missing queue capability, player-safe validation text and bundled-template boundary enforcement. No migration, Composer dependency, bundled-module version or VDS update-agent contract changed. The cumulative 0.47.15 package supports updates from 0.42.4 through 0.47.14.

## 0.47.14 - 2026-08-02

### Fixed

- Fixed the PHPStan `if.alwaysFalse` regression in `UpdatePackageInspector` introduced by the repeated staging symbolic-link race-condition guard.
- Preserved both runtime checks before and after staging-directory creation by routing them through a dedicated assertion method instead of removing the second security check.
- Added a Unix regression confirming that a symbolic-link staging root is rejected.
- Kept Web Update behavior and VDS update-agent contract version 3 unchanged; no agent reinstall, migration, Composer dependency, bundled-module version, or theme version change is required. The cumulative 0.47.14 package supports updates from 0.42.4 through 0.47.13.

## 0.47.13 - 2026-08-02

### Fixed

- Fixed Web Update package inspection on root-owned VDS installations where `storage/app/kaevcms/updates/staging` correctly belongs to the deployment owner and PHP-FPM group with mode `2770`.
- Stopped requiring PHP-FPM to run `chmod` on an existing secure staging directory that is already writable through its group; Linux permits only the owner or root to change that mode.
- Preserved staging security checks: symbolic links are rejected, newly created or world-accessible directories are normalized when possible, and unsafe or non-writable directories still fail closed.
- Added a regression that forces `chmod` failure and confirms an existing secure group-writable staging directory remains usable.
- Kept VDS update-agent contract version 3 unchanged; no agent reinstall, migration, Composer dependency, bundled-module version, or theme version change is required. The cumulative 0.47.13 package supports updates from 0.42.4 through 0.47.12.

## 0.47.12 - 2026-08-02

### Fixed

- Fixed Pint formatting in the VDS update filesystem transaction and Web Updater release regression.
- Resolved PHPStan always-true type checks without removing runtime validation: malformed deployment user, UID, web group or GID metadata still fails before any application file is changed.
- Added regressions for root-owned and regular-user-owned systemd service identities, mandatory reinstall of legacy agent v2 registrations, a clean no-request agent run, and malformed deployment identity metadata.
- Kept VDS update-agent contract version 3 and the existing systemd/ownership model unchanged; an already ready v3 agent does not require reinstallation.
- No database migration, Composer dependency, bundled-module version, or theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.12 package supports direct updates from 0.42.4 through 0.47.11.

## 0.47.11 - 2026-08-02

### Fixed

- Moved GNU `find` global `-mindepth` options before path expressions in the VDS update-agent installer, removing the warnings shown during installation or reinstallation.
- Added a release regression that requires the warning-free option order in both protected public-path and KaevCMS runtime scans.
- Kept agent contract version 3 and the existing root/regular-user ownership model unchanged; no agent reinstall is required solely for this maintenance update.
- No database migration, Composer dependency, bundled-module version, or theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.11 package supports direct updates from 0.42.4 through 0.47.10.

## 0.47.10 - 2026-08-02

### Fixed

- Reworked the VDS agent installer into a single-command flow that requests sudo automatically when required and works from both root and regular administrative shells.
- Added automatic project-owner and PHP-FPM identity detection, with explicit `--project-user`, `--web-user`, and `--web-group` overrides for non-standard servers.
- Repaired application ownership and group-readable modes during agent installation while keeping PHP-FPM without source-code write access.
- Repaired Web Update request, package, staging, cache, log, upload, and KaevCMS runtime paths with protected group-write inheritance.
- Normalized ownership, group, and modes for every application file replaced by the VDS agent and for files restored during rollback, preventing selective HTTP 500 permission failures.
- Added access probes for PHP-FPM reads and Web Update writes, agent contract version 3 metadata, RU/EN documentation, and permission regressions.
- No database migration, Composer dependency, bundled-module version, or theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.10 package supports direct updates from 0.42.4 through 0.47.9.

## 0.47.9 - 2026-08-02

### Fixed

- Added an isolated test-only `APP_KEY` to PHPUnit so clean release trees do not depend on a server `.env` key.
- Synchronized shared administration tab expectations and the visual active-tab contract with the current generated class order and computed `display: flex` value.
- Made the isolated Support Tickets UI test explicitly enable and boot its module before checking module routes.
- Updated CLI updater and VDS agent tests for the protected group-write contract introduced in 0.47.8.
- Made the browser runner create and remove a temporary `.env` marker only when a clean release tree has no physical file.
- Added a regression for the mail category on the administration audit log.
- Application runtime behavior, migrations, Composer dependencies, bundled-module versions and theme versions are unchanged. The cumulative update line remains based on `0.42.4`; the 0.47.9 package supports direct updates from 0.42.4 through 0.47.8.

## 0.47.8 - 2026-08-02

### Fixed

- Fixed VDS browser-update uploads on root-owned installations where `storage/app/kaevcms/updates/packages` or `staging` had been created with owner-only permissions.
- Updated the VDS agent installer to create and repair request, package and staging directories as deployment-owner/PHP-FPM-group runtime paths with setgid group inheritance, without granting PHP-FPM write access to application source files.
- Changed the CLI updater to preserve protected group access (`2770` directories and `0660` package files) instead of locking the Web Updater out with `0700`/`0600` permissions.
- Bumped the local agent contract to version 2. Older registrations are shown as needing repair and can be fixed by rerunning the existing installer command.
- Added clear upload/staging permission errors, RU/EN documentation and PHPUnit/release regressions for the shared runtime-directory contract.
- No migration, Composer dependency, bundled-module version or theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.8 package supports direct updates from 0.42.4 through 0.47.7.

## 0.47.7 - 2026-08-02

### Changed

- Added shared Blade components for administration cards, card headings, buttons, top-level and contextual tabs, and filter bars while preserving the established light visual style and existing compatibility classes.
- Migrated Settings, Mail, Audit, Users, Daily Rewards and Support Tickets reference screens to the shared UI contracts; removed duplicate legacy styling for audit tabs and user filters.
- Fixed the Support Tickets editor-permission regression introduced by the form unification: toggle titles and hints render as separate block lines again, so dependent controls remain readable and the Playwright contract passes.
- Added PHPUnit and Playwright regressions for the shared card/button/tab/filter system, responsive layouts and the editor-permission label contract.
- No migration, Composer dependency, bundled-module version or theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.7 package supports direct updates from 0.42.4 through 0.47.6.

## 0.47.6 - 2026-08-02

- Added shared `x-admin.field` and `x-admin.toggle` Blade components for standard administration forms.
- Standardized field height, thin borders, focus states, hints and validation errors around the established Security settings appearance.
- Replaced legacy publication and module checkboxes with the modern administration switch used by Registration and Game Accounts.
- Unified validation error classes across system settings, GameServer/LoginServer management and bundled modules, including accessible `role="alert"` output and `aria-invalid` on migrated fields.
- Removed obsolete duplicate field and switch CSS while retaining visual compatibility for existing `form-group` markup.
- Added PHPUnit and Playwright regressions for shared form components, desktop dimensions, mobile overflow and switch interaction.
- No database migration, Composer dependency or bundled-theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.6 package supports direct updates from 0.42.4 through 0.47.5.

## 0.47.5 - 2026-08-02

### Fixed

- Added the missing literal `GameServer` key to RU/EN JSON localization so the compact external-database diagnostic card no longer depends on fallback rendering.
- Updated the Web Updater feature expectation to the final compact trusted-package warning introduced in 0.47.4.
- Updated the APP_KEY Playwright regression to use the stable `system-app-key-card` test id instead of the heading wrapper changed by the compact-card layout.
- Corrected the stale Russian release paragraph in README and synchronized release metadata regressions for this maintenance package.
- No application runtime behavior, migration, Composer dependency, bundled-module or theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.5 package supports direct updates from 0.42.4 through 0.47.4.

## 0.47.4 - 2026-08-02

### Changed

- Replaced the large LoginServer and GameServer diagnostic sections on **System information** with three compact cards for APP_KEY, LoginServer and GameServer. The connection cards show only aggregate configured/available counts, current state, last check and a direct settings link.
- Retained safe external-database refresh and support-report diagnostics without showing server names, schema tables, capabilities, database credentials or other verbose details on the page.
- Removed the separate trusted-source warning above the Web Updater upload card. The existing upload description is now one compact red warning that explains official-source use, program-file replacement, pre-install verification and owner responsibility.
- Added PHPUnit and Playwright regressions for aggregate connection states, three-card desktop layout, responsive overflow, hidden detailed server data and the single compact update warning.
- No migration, Composer dependency, bundled-module or theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.4 package supports direct updates from 0.42.4 through 0.47.3.

## 0.47.3 - 2026-08-02

### Fixed

- Allowed `deployment/vds/install-update-agent.sh` to install the VDS update agent when the KaevCMS project is intentionally owned by `root` instead of rejecting that deployment model.
- Added an explicit root-privilege warning: a selected update package is applied with root access and must come from a trusted source. PHP-FPM still receives access only to the dedicated request directory.
- Improved PHP-FPM group detection for root-owned projects by falling back to the standard `www-data` group when `storage` is still owned by `root`; non-standard pools can use `KAEVCMS_WEB_GROUP`.
- Documented root and non-root installation, reinstallation and diagnostics in RU/EN and added release regressions for the original root-owner failure.
- No migration, Composer dependency, bundled-module or theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.3 package supports direct updates from 0.42.4 through 0.47.2.

## 0.47.2 - 2026-08-02

### Fixed

- Synchronized `ReleaseMetadataTest` with the actual 0.47.2 `release.json` repair contract. The 0.47.1 package still expected the complete 0.47.0 VDS updater file list, while its metadata correctly contained only the two test repairs.
- Kept the correction limited to release metadata verification. No application runtime, database migration, Composer dependency, bundled-module version or theme version changed.
- The cumulative update line remains based on `0.42.4`; the 0.47.2 package supports direct updates from 0.42.4 through 0.47.1.

## 0.47.1 - 2026-08-02

### Fixed

- Removed an extra blank line from `WebUpdaterReleaseTest` so Laravel Pint no longer fails the VDS updater release gate.
- Aligned the exact Playwright assertion with the complete trusted-source warning rendered by the system update page without weakening the warning coverage.
- No runtime behavior, migration, Composer dependency, bundled-module or theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.1 package supports direct updates from 0.42.4 through 0.47.0.

## 0.47.0 - 2026-08-02

### Added

- Added a manually installed Ubuntu VDS update agent based on per-installation `systemd.path` and one-shot `systemd.service` units. Verified Web Update requests are queued in protected runtime storage and executed as the project deployment owner without granting PHP-FPM write access to application source files or opening a network port.
- Integrated the agent with the existing Web Updater pipeline: package inspection remains in the administration panel, while the agent rechecks the archive hash and real file permissions before using the established backups, maintenance mode, filesystem transaction, migrations, cache cleanup, queue restart, logging and rollback flow.
- Added agent registration, status and worker Artisan commands, encrypted temporary recovery-secret storage, one-request concurrency protection, atomic request files and recovery-safe status tracking.
- Added clear agent setup, diagnostics and removal instructions in RU/EN VDS and update documentation. Existing installations below 0.47.0 use the CLI Updater once, then install the agent for future browser-started updates.

### Changed

- Added an explicit trusted-source confirmation before any system update can start. The interface explains that update archives can replace program files and that the site owner is responsible for selecting a trusted source.
- Changed Ubuntu/MySQL documentation examples to use `kaevcms_db` for the database and `kaevcms_user` for the database account, including SQL, connection verification and Web Installer fields.
- Added PHPUnit, release-contract and Playwright regressions for agent registration, encrypted queue state, archive revalidation, missing-agent guidance, trusted-source validation, shipped systemd units, documentation and mobile updater layout.
- Added one CMS migration for VDS-agent execution state. No Composer dependency, bundled-module or theme version changed. The cumulative update line remains based on `0.42.4`; the 0.47.0 package supports direct updates from 0.42.4 through 0.46.5.

## 0.46.5 - 2026-08-01

### Fixed

- Replaced the dashboard disk fill element and CSP-blocked inline width with a native accessible `<progress>` control, preserving normal, warning and danger colors in Chromium, WebKit and Firefox.
- Kept the Kaev Aurelia Account mobile backdrop outside the 286 px sidebar area so blur and pointer handling never cover the open navigation panel.
- Removed case-insensitive duplicate `players` translation keys and tightened `DashboardPlayerOverview` typed-array access for PHPStan.
- Contained footer grids, social links and long content inside mobile viewports for both bundled public themes; Kaev Aurelia now collapses its footer to one column at the existing mobile breakpoint.
- Updated L2 Dark Classic to `0.8.2`, Kaev Aurelia to `1.0.9` and Kaev Aurelia Account to `1.6.4`; added PHPUnit and Playwright regressions for strict-CSP disk rendering, menu/backdrop geometry and public-theme footer overflow.
- No migration, dependency or bundled-module version change was added. The cumulative update line remains based on `0.42.4`; the 0.46.5 package supports direct updates from 0.42.4 through 0.46.4.

## 0.46.4 - 2026-08-01

### Added

- Added a compact **Players** card below Storage in the wide dashboard column with registered KaevCMS users, active game accounts, available character totals and the Support Tickets attention badge when that module is enabled and accessible to the administrator.
- Character totals are cached briefly, count only normal non-deleted characters across currently configured game databases and degrade to a partial or unavailable note instead of breaking the dashboard when an external database is offline.

### Fixed

- Replaced the mobile account-menu `html::after` overlay with an explicit shared backdrop component. The backdrop now sits below the sidebar, receives close clicks and owns the blur, while the menu remains sharp, above the overlay and fully interactive in both bundled account themes.
- Updated L2 Obsidian Luxury and Kaev Aurelia Account to `1.6.3` and added PHPUnit and Playwright regressions for dashboard permissions/counts, module-aware support metrics, backdrop layering, direct backdrop closing, menu-link clicks and responsive placement.
- No migration, dependency or bundled-module version change was added. The cumulative update line remains based on `0.42.4`; the 0.46.4 package supports direct updates from 0.42.4 through 0.46.3.

## 0.46.3 - 2026-08-01

### Fixed

- Replaced the oversized aggregate online card with compact last-updated text and the existing **Check now** action, while retaining per-GameServer online counts in each server row.
- Moved the GameServer card into the right dashboard column above LoginServer, leaving the storage overview in the wider left column and keeping system operations full-width below.
- Rendered disk usage with a direct inline percentage on the fill element instead of a nested CSS `min()`/`max()` custom-property expression. The filled segment now paints immediately after page load without requiring a browser repaint.
- Added PHPUnit and Playwright regressions for removal of the aggregate counter, per-server online visibility, desktop card placement, compact refresh controls, initial disk-bar width and a visibly distinct fill color.
- No migration, dependency or bundled-module version change was added. The cumulative update line remains based on `0.42.4`; the 0.46.3 package supports direct updates from 0.42.4 through 0.46.2.

## 0.46.2 - 2026-08-01

### Added

- Added a **Storage** dashboard card in the wider left column with server-disk totals, used/free capacity, percentage state and a compact administration-style progress bar.
- Added safe KaevCMS database storage details for MySQL, MariaDB and SQLite: engine/version, total size, data, indexes and table count where the active database and hosting permissions expose those values.
- Kept database names, hosts, usernames, passwords, DSNs and SQLite paths out of dashboard output; unavailable hosting statistics degrade to a clear non-fatal message.
- Reused existing dashboard cards, status badges, spacing and typography without adding a chart dependency, migration or bundled-module change.
- Added PHPUnit and Playwright regressions for database engines, restricted statistics, permissions, accessibility, responsive layouts and horizontal overflow.
- The cumulative update line remains based on `0.42.4`; the 0.46.2 package supports direct updates from 0.42.4 through 0.46.1.

## 0.46.1 - 2026-08-01

### Fixed

- Expanded the existing administration switch checkbox over the complete 46×26 control, restoring normal direct pointer clicks while preserving the current visual component, keyboard focus and label behavior.
- Limited notification-settings help-tooltip width below 520 px so hidden absolute tooltip content cannot enlarge the mobile document horizontally.
- Sorted notification service imports in `AppServiceProvider` according to Laravel Pint.
- Added a Playwright regression that verifies the checkbox itself exposes the full clickable target and toggles through real pointer clicks; the existing mobile test continues to guard tooltip overflow.
- No migration, dependency or bundled-module version change was added. The cumulative update line remains based on `0.42.4`; the 0.46.1 package supports direct updates from 0.42.4 through 0.46.0.

## 0.46.0 - 2026-08-01

### Added

- Added **Settings → Notifications** with the existing administration switch component for grouped actionable sources: Technical Support, module updates, KaevCMS updates, background tasks, LoginServer availability, GameServer availability, low disk space, leftover installer and critical system diagnostics.
- Added the existing accessible `?` tooltip component beside every source, with short Russian and English explanations that avoid internal migration, queue and Scheduler terminology in the visible labels.
- Added a settings gear to the right of **All / Unread** in the notification dropdown. It is available to roles with settings access, remains responsive on mobile and opens the notification settings tab directly.
- Added configurable automatic cleanup and 30/60/90/180-day retention. Disabling automatic cleanup does not affect the manual `kaevcms:notifications-clean --days=<number>` command.
- Disabled sources no longer create one-time notifications. Recurring problems are resolved in the notification lifecycle without deleting existing list entries, so re-enabling a still-active source creates a fresh event on the next scan while diagnostics continue to show the real state.
- Added PHPUnit and Playwright regressions for saving preferences, access control, grouped Support Ticket events, separate LoginServer/GameServer switches, recurring-problem reactivation, cleanup behavior, tooltip interaction, gear navigation and mobile overflow.
- No migration, dependency or bundled-module version change was added. The cumulative update line remains based on `0.42.4`; the 0.46.0 package supports direct updates from 0.42.4 through 0.45.5.

## 0.45.5 - 2026-08-01

### Fixed

- Switched the isolated Playwright rate-limiter store from transient `array` cache to the per-run temporary SQLite database while keeping the ordinary browser cache in memory. Repeated HTTP requests now exercise the real three-diagnostic-downloads-per-minute contract without sharing state across runs.
- Synchronized the runtime cache PHPUnit contract with the isolated database limiter and retained the Windows SQLite cleanup regression.
- Removed unreachable null-coalescing fallbacks from mandatory regex callback matches, resolving the PHPStan `nullCoalesce.offset` findings without changing IPv4/IPv6 redaction behavior.
- Added no migration, dependency, bundled-module or user-facing behavior change. The cumulative update line remains based on `0.42.4`; the 0.45.5 package supports direct updates from 0.42.4 through 0.45.4.

## 0.45.4 - 2026-08-01

### Fixed

- Collapsed identical recent Laravel warning/error signatures in diagnostic packages into one line with occurrence count and first/last timestamps, preventing one repeated problem from flooding `recent-errors.log`.
- Replaced over-broad IP regular expressions with validated IPv4/IPv6 redaction, preserving ISO timestamps and package/operating-system/database version strings while still removing real addresses.
- Replaced the diagnostics route HTTP 429 page with a per-administrator three-downloads-per-minute guard that redirects back and shows a small localized wait message under the download button.
- Removed the duplicate **Manage updates** action from System information because the existing **System updates** tab already provides the same navigation.
- Added PHPUnit and Playwright regressions for signature aggregation, IP-safe timestamps/versions, friendly throttling, and removal of the duplicate update action.
- No migration, dependency or bundled-module version change was added. The cumulative update line remains based on `0.42.4`; the 0.45.4 package supports direct updates from 0.42.4 through 0.45.3.

## 0.45.3 - 2026-08-01

### Fixed

- Synchronized `ReleaseMetadataTest` with the 0.45.2 `release.json` repair contract. The test still expected the older 0.45.1 notification-center file list, causing the otherwise valid 0.45.2 release metadata gate to fail.
- Kept the correction limited to release metadata verification. No application runtime, database migration, dependency, bundled-module version or user-facing behavior changed.
- The cumulative update line remains based on `0.42.4`; the 0.45.3 package supports direct updates from 0.42.4 through 0.45.2.

## 0.45.2 - 2026-08-01

### Fixed

- Selected the Composer `ClassLoader` that actually owns the KaevCMS core classes when registering module namespaces, instead of mutating an arbitrary first loader such as the PHPStan PHAR loader.
- Corrected nullable notification model metadata and strict PHPStan contracts for diagnostic redaction, notification payload normalization, runtime diagnostics, encryption health and disk-space probes.
- Corrected three PHPUnit regressions: missing notification targets now resolve to no action URL, and literal `$ticket` / `$installerRemoved` source assertions no longer interpolate test variables.
- Stacked the system overview actions below 900 px so the diagnostics page no longer expands a 768 px viewport horizontally.
- Added a regression proving module namespaces are registered on the project Composer loader even when another loader is prepended to the process.
- No migration, dependency or bundled-module version change was added. The cumulative update line remains based on `0.42.4`; the 0.45.2 package supports direct updates from 0.42.4 through 0.45.1.

## 0.45.1 - 2026-08-01

### Added

- Added a personal administrator notification center in the top panel with a neutral bell, red unread counter capped at `99+`, compact All/Unread filters, severity icons, direct links and responsive desktop/mobile behavior.
- Added per-administrator read state and list cleanup: mark all as read, clear read notifications, and clear all with confirmation. Reading or dismissing an event never changes the underlying diagnostic/server/queue state.
- Added deduplicated actionable sources for new Support Tickets and player replies, bundled-module updates and pending migrations, pending CMS migrations, failed CMS updates, queue/Scheduler problems, unavailable configured LoginServer/GameServer connections, low disk space, a leftover installer and critical encryption diagnostics.
- Added recurring-problem lifecycle tracking so an unresolved failure updates one record without recreating its badge after dismissal, while a resolved problem that later returns creates a new notification.
- Added scheduled scanning every minute and physical retention cleanup, with safe behavior before the notification migration is applied and no notification failure allowed to break the source operation.
- Updated bundled Support Tickets to `1.6.0` for KaevCMS `0.45.1+`; ticket subjects, bodies, player names and email addresses are never copied into notification payloads. No module migration or dependency change was added.
- Added PHPUnit and Playwright regressions for recipient permissions, administrator isolation, idempotency, recurring problem resolution, route allowlists, `99+`, filters, bulk actions, installer detection, ticket privacy, direct navigation, confirmation and responsive integration.
- The cumulative update line remains based on `0.42.4`; the 0.45.1 package supports direct updates from 0.42.4 through 0.45.0.

## 0.45.0 - 2026-08-01

### Added

- Added an administrator diagnostic package under **Settings → System information** with a readable report and separate sanitized snapshots for versions, environment, permissions, CMS and external databases, scheduler, queue, disk space, modules, module/core migrations, recent CMS updates and recent error signatures.
- Added layered secret and personal-data redaction for structured values and text, covering `APP_KEY`, passwords, tokens, authorization data, cookies, database/mail credentials, email addresses, IP addresses, DSNs, credential-bearing URLs and absolute project paths.
- Reduced Laravel warnings and errors to timestamp, severity, exception class and a short SHA-256 fingerprint; raw log messages, `.env`, user rows, database files and complete logs are never copied into the archive.
- Added temporary package cleanup, download auditing, a three-downloads-per-minute limit and explicit access for owners, administrators and trusted auditors with system-information permission.
- Added RU/EN administration documentation plus unit, feature, permission, UI and secret-leakage regression contracts. No database migration, module update or dependency change was added.
- The cumulative update line remains based on `0.42.4`; the 0.45.0 package supports direct updates from 0.42.4 through 0.44.34.

## 0.44.34 - 2026-07-31

### Security

- Removed the public `/install` directory automatically only after the database, owner, release marker and `installed.lock` are created successfully; interrupted or failed installations retain the installer for safe resume.
- Rendered the final installation result in the successful POST response so cleanup can happen before `/install/` becomes unavailable, with an explicit manual fallback when filesystem permissions prevent deletion.
- Added symlink-safe, idempotent recursive cleanup limited to the fixed public installer directory without touching sibling runtime files.
- Kept full releases installable while ensuring cumulative Web Updates never include the installer, and made installed Windows update cleanup remove any leftover `public/install` directory.
- Updated RU/EN installation and shared-hosting documentation plus standalone installer, PHPUnit and Windows workflow regression contracts. No database migration, module update or dependency change was added.
- The cumulative update line remains based on `0.42.4`; the 0.44.34 package supports direct updates from 0.42.4 through 0.44.33.

## 0.44.33 - 2026-07-31

### Fixed

- Started the isolated Playwright HTTP server with `artisan serve --no-reload`, preserving the runner-provided SQLite database, application key, test credentials and rate-limit environment on Windows instead of allowing the reload child process to fall back to the normal `.env`.
- Made the mobile administration backdrop regression use the actual element bounds, avoiding an out-of-range fixed coordinate when a Windows scrollbar reduces the viewport width.
- Corrected Support Tickets hardening regressions so reply text is prepared before access is revoked and exactly one subsequent Livewire action is required to return 403 without creating a message.
- Applied the project Pint formatting to `SafeHtmlSanitizer` and the affected infrastructure/module tests without changing sanitizer behavior or weakening any assertion.
- Added release and runtime contracts for the no-reload browser server. Support Tickets remains at 1.5.2 and no database migration or dependency update was added.
- The cumulative update line remains based on `0.42.4`; the 0.44.33 package supports direct updates from 0.42.4 through 0.44.32.

## 0.44.32 - 2026-07-31

### Fixed

- Stabilized the accessible name of administrator module links when a live attention badge is present; the badge remains an independent polite status region for assistive technologies.
- Kept all LoginServer and GameServer browser fixtures fresh after seeding so the first dashboard visit cannot replace deterministic `17 ms` and `23 ms` diagnostics with unsupported-driver results.
- Removed the unused `Filesystem` dependency from `ModuleRuntime`, resolving the PHPStan `property.onlyWritten` failure without suppressions.
- Corrected Support Tickets hardening tests so guarded user activation and email-verification columns are actually changed before asserting that an open Livewire conversation is forbidden.
- The cumulative update line remains based on `0.42.4`; the 0.44.32 package supports direct updates from 0.42.4 through 0.44.31.

## 0.44.31 - 2026-07-31

- Stopped scheduled server monitoring from logging `GameServer database monitoring failed` for the intentionally incomplete default GameServer placeholder. Missing drivers, LoginServer selection or database credentials now produce a quiet `not_configured` snapshot with zero failures instead of a recurring warning.
- Hardened cumulative updates around the public installer: `public/install` is excluded from payload files and added to the update deletion list, so updating an installed CMS removes a leftover installer and never restores `/install/`.
- Updated bundled Support Tickets to 1.5.2 without a database migration. Its administration badge now remains visually hidden at zero even with the menu badge display rule.
- Restricted support reply, note and edit text areas to vertical resizing with fixed container width, preventing action buttons from being pushed outside the ticket card.
- Replaced the large “Edit message” text action with a compact accessible pencil button in the message header and updated PHPUnit, package-builder and Playwright regressions for all five fixes.
- The cumulative update line remains based on `0.42.4`; the 0.44.31 package supports direct updates from 0.42.4 through 0.44.30.

## 0.44.30 - 2026-07-31

- Added a shared module PSR-4 autoloader used by both runtime boot and database migrations. Pending migrations can now safely reference constants, models or services shipped by the incoming module version without activating its bootstrap or routes first.
- Fixed the Support Tickets 1.5.1 update path from 1.4.2: the unchanged `2026_07_30_230000_seed_support_ticket_settings.php` migration can now load `SupportTicketSettings` and complete normally after the CMS update.
- Replaced the mobile administration menu with an accessible off-canvas drawer: a compact sticky toolbar opens it above the page, while close button, backdrop, Escape and navigation links close it without affecting the desktop sidebar.
- Preserved accordion group state, automatic opening of the active group, the live Support Tickets badge, body scroll locking and reduced-motion behavior inside the new drawer.
- Added PHPUnit and Playwright regressions for module-class autoload during migrations and the complete mobile drawer lifecycle. No database migration, module version or dependency change was added.
- The cumulative update line remains based on `0.42.4`; the 0.44.30 package supports direct updates from 0.42.4 through 0.44.29.

## 0.44.29 - 2026-07-31

- Reworked the administration navigation below 760 px: group headings remain visible, native expand/collapse works on touch devices, the active group opens automatically and nested links are visually separated instead of merging into one horizontal list.
- Stabilized player-account module navigation by removing hover-prefetch from dynamic module links, adding stable module identifiers and strengthening the Daily Rewards browser regression around the real navigation link.
- Kept browser-test server-monitor snapshots fresh for the full suite, preventing the intentionally unsupported test drivers from repeatedly filling the test log with expected database-monitoring warnings.
- Added mobile Playwright and PHPUnit contracts for grouped navigation, module-link routing and the test-only monitoring interval. No database migration, module update or dependency change was added.
- The cumulative update line remains based on `0.42.4`; the 0.44.29 package supports direct updates from 0.42.4 through 0.44.28.

## 0.44.28 - 2026-07-31

- Updated bundled `support-tickets` to 1.5.1 and moved its administration attention badge into a persistent Livewire component: staff replies, closing and reopening refresh it immediately, while 30-second polling discovers tickets changed in other sessions without reloading the page.
- Made `kaevcms:cache-clean` use the configured database-cache connection, table, lock connection and lock table instead of hard-coded defaults; command output now identifies the actual connection and table being maintained.
- Strengthened fail-closed retention cleanup by validating raw `retention_months` and `automatic_cleanup_enabled` values before any preview or deletion. Unknown values now stop cleanup instead of falling back to defaults.
- Added PHPUnit and Playwright regressions for persisted badge updates, custom cache storage and malformed retention settings. No database migration, Composer dependency or npm dependency was added.
- The cumulative update line remains based on `0.42.4`; the 0.44.28 package supports direct updates from 0.42.4 through 0.44.27.

## 0.44.27 - 2026-07-30

- Updated bundled `support-tickets` to 1.5.0 and added a red administration-menu badge that counts New and In-progress tickets requiring a staff response, hides at zero, displays `99+` above 99 and refreshes immediately after ticket actions.
- Made retention cleanup fail closed: scheduled and manual destructive cleanup now stop with an explicit error when module settings cannot be read; a new immutable migration seeds the default settings row without overwriting owner changes.
- Re-applied active-account and configured email-verification checks to subsequent player Livewire requests, added close throttling, restricted retention protection to Owner/Administrator and made repeated assign/close/protection actions idempotent.
- Decoupled ticket-history reading from the current write limit, added stable list ordering and preserved the bounded 2,000-message safety ceiling.
- Replaced unsafe byte truncation in the shared HTML sanitizer with a validation error, and added the matching UTF-8 byte-size check to the Tiptap source and compiled bundle.
- Added indexed scheduled cleanup for expired database cache/rate-limiter rows and daily orphan-media cleanup for news and pages.
- Added focused regressions for UTF-8 boundaries, cache maintenance, fail-closed retention, Livewire account-state revocation, historical messages, idempotent transitions and the support attention badge.
- The cumulative update line remains based on `0.42.4`; the 0.44.27 package supports direct updates from 0.42.4 through 0.44.26.

## 0.44.26 - 2026-07-30

- Added the core `ModuleAdminComponent` base class for administrative Livewire modules. It inherits `Livewire\Component` and uses `AuthorizesModuleAdminAccess` inside the PHPStan analysed `app` tree.
- Updated bundled `support-tickets` to 1.4.2 so `AdminTicketConversation` inherits the shared component without changing its authorization behaviour.
- Added release and module contract tests for the inheritance relationship. PHPStan rules, baseline and `treatPhpDocTypesAsCertain` remain unchanged.
- No database migrations, Composer dependencies, npm dependencies or public UI changes were introduced.
- The cumulative update line remains based on `0.42.4`; the 0.44.26 package supports direct updates from 0.42.4 through 0.44.25.

# Changelog

## 0.44.25 - 2026-07-30

- Updated bundled `support-tickets` to 1.4.1: the back action is anchored on the left, the ticket status occupies the centered toolbar column and staff actions remain aligned on the right with responsive stacking.
- Rounded the player reply, staff reply, message edit and internal-note text areas consistently with the rest of the account and administration controls.
- Expanded the strict Tiptap schema to 24 text colors and 12 highlights across the editor, server sanitizer and both public themes. Arbitrary `style` and `class` attributes remain forbidden.
- Fixed the Support Tickets module-image expectation and the Livewire authorization rendering assertion, and corrected the reported Pint formatting in the release builder and module access unit test.
- Added PHPUnit and Playwright contracts for the toolbar layout, rounded composers and expanded safe palette. No database migration was added.
- The cumulative update line remains based on `0.42.4`; the 0.44.25 package supports direct updates from 0.42.4 through 0.44.24.

## 0.44.24 - 2026-07-30

- Replaced the legacy `document.execCommand` news/page editor with a locally bundled Tiptap 3.29.2 editor shared by both content types; no CDN or production Node.js runtime is required.
- Added undo/redo, headings, lists, quotes, inline and block code, safe text colors/highlights/sizes, four alignments, horizontal rules, tables, full-screen mode and character counting.
- Added accessible link and image dialogs, safe URL normalization, existing protected upload routes, alt text, captions, image alignment and four controlled image sizes.
- Extended `SafeHtmlSanitizer` only with table elements and bounded `data-*` tokens; arbitrary styles, classes, event handlers, external images and unsafe URL schemes remain forbidden.
- Updated both bundled public themes for identical `.news-prose` and `.cms-page-prose` rendering of the new tokens, responsive figures, tables and code blocks.
- Added the readable editor source, pinned npm dependencies, compiled bundle license notices and PHPUnit/Playwright contracts. Fixed required validation for image-only, divider-only and table-only documents and avoided invalid nested forms in editor dialogs.
- The cumulative update line remains based on `0.42.4`; the 0.44.24 package supports direct updates from 0.42.4 through 0.44.23.

## 0.44.23 - 2026-07-30

- Updated bundled `support-tickets` to 1.4.0 and moved manual database cleanup into a dedicated settings tab using the shared administration tab component. The save action now remains below the complete general-settings form.
- Reworked the Support Tickets administration detail page into a two-column layout with the conversation on the left and ticket metadata in a compact right-side panel, matching the established Users detail pattern.
- Replaced the previous Support Tickets filter card with the shared one-line administration filter bar and conditional reset action used by the Users catalogue.
- Replaced bundled module artwork for Daily Rewards, Promo Codes and Support Tickets with the supplied designs, normalized to the required validated 512×512 WebP format. Daily Rewards and Promo Codes are now 1.3.1.
- Added PHPUnit, Playwright, release-manifest and artwork-dimension regressions for the new tabs, layouts, filters and catalogue images. No database migration or dependency update was added.
- The cumulative update line remains based on `0.42.4`; the 0.44.23 package supports direct updates from 0.42.4 through 0.44.22.

## 0.44.22 - 2026-07-30

- Integrated the externally verified module-access and browser-cache fixes into the current source without regressing the newer Windows SQLite cleanup and explicit browser login diagnostics.
- Normalized module role lists before constructing access rules, preserving runtime validation through `array<array-key, mixed> -> list<AdminRole>` while remaining compatible with strict PHPStan analysis.
- Added `RuntimeCacheConfigurationTest.php` to `repair_files` alongside the module access registry and browser runner so the direct patch force-replaces all three verified files on installations affected by incomplete earlier updates.
- Kept production `CACHE_LIMITER=database`; Playwright continues to use isolated array stores and a testing-only login limit.
- The cumulative update line remains based on `0.42.4`; the 0.44.22 package supports direct updates from 0.42.4 through 0.44.21.

## 0.44.21 - 2026-07-30

- Added a runtime role normalizer for module administration rules, preserving defensive validation while satisfying strict PHPStan contracts without weakening analysis.
- Added a test-only browser login limit override and environment-backed public authentication limits; Playwright now keeps production throttling unchanged while avoiding the shared 127.0.0.1 login cascade.
- Added `repair_files` support to the reproducible release builder and marked the module access registry and browser runner for forced replacement, repairing installations where an earlier patch was only partially applied.
- Added regressions for invalid module role values, browser login-limit isolation and repair-file release metadata.
- The cumulative update line remains based on `0.42.4`; the 0.44.21 package supports direct updates from 0.42.4 through 0.44.20.

## 0.44.20 - 2026-07-30

- Updated bundled `support-tickets` to 1.3.1 and closed the retention-cleanup race: eligible tickets are selected with `lockForUpdate()` and rechecked with the closed, unprotected and cutoff predicates immediately before deletion.
- Cleanup reports now count only tickets, messages and revisions that were actually deleted; tickets reopened, protected or given a newer close date during cleanup are preserved.
- Added a shared `ModuleAdminAuthorizer` and `AuthorizesModuleAdminAccess` trait so normal administration routes and module Livewire actions use the same fail-closed access rules.
- Converted the Support Tickets administration Livewire component from duplicated role checks to registered module route permissions and added Owner, Administrator, Editor, Auditor, player and guest regressions.
- Added explicit Playwright login diagnostics for HTTP 429 and server errors across administrator, player and support-ticket browser flows; production rate limits remain unchanged.
- Added browser-helper unit tests and module-development documentation explaining that route registration alone does not protect `/livewire/update`.
- The cumulative update line remains based on `0.42.4`; the 0.44.20 package supports direct updates from 0.42.4 through 0.44.19.

## 0.44.19 - 2026-07-30

- Removed redundant `AdminRole` runtime filtering and list reindexing from the typed module administration access registry, resolving the three reported PHPStan findings without weakening analysis.
- Isolated Playwright rate-limit counters with `CACHE_LIMITER=array`, so browser authentication no longer depends on the production database-cache store while the application default remains `database`.
- Restored the standard `cache_locks` table default for the database cache store.
- Made browser test shutdown wait for the PHP process tree and retry locked SQLite cleanup on Windows; a cleanup failure no longer hides the original Playwright failure.
- Added regressions for browser limiter isolation, the database lock-table default and locked-file cleanup behavior.
- The cumulative update line remains based on `0.42.4`; the 0.44.19 package supports direct updates from 0.42.4 through 0.44.18.

## 0.44.18 - 2026-07-30

- Added a separate `cache.limiter` store with `CACHE_LIMITER=database` by default, keeping the general application cache file-based while moving login and other rate-limit counters to the existing CMS `cache` table.
- Added `kaevcms:runtime-directories --probe`, which creates the required Laravel runtime tree and verifies nested directory and file creation without leaving probe files behind.
- Added runtime-directory verification before and after `optimize:clear` in Windows setup/update, the Web Installer, Web/CLI update installation and interrupted-update recovery.
- Strengthened Web Installer write checks to verify recursive directory creation rather than only writing one file into an existing parent.
- Added PHPUnit, Web Installer, Windows workflow and Ubuntu/VDS documentation regressions for the limiter store, runtime repair order and nested write probes.
- Documented safe `storage` and `bootstrap/cache` ownership repair without `chmod 777` and added a PHP-FPM-user diagnostic command.
- The cumulative update line remains based on `0.42.4`; the 0.44.18 package supports direct updates from 0.42.4 through 0.44.17.

## 0.44.17 - 2026-07-29

- Updated bundled `support-tickets` to 1.3.0, restored ticket pages after the Livewire conversion and corrected the remaining Pint PHPDoc alignment finding.
- Passed the authenticated player explicitly to account-theme layouts and resolved the administration path inside the staff Livewire component instead of accepting a nullable route parameter.
- Moved ticket, message, revision, open-ticket and character limits into owner-only module settings with bounded validation and unchanged safe defaults.
- Added a module migration and regressions for configurable limits while preserving existing tickets, messages and cleanup settings.
- The cumulative update line remains based on `0.42.4`; the 0.44.17 package supports direct updates from 0.42.4 through 0.44.16.

## 0.44.16 - 2026-07-29

- Updated bundled `support-tickets` to 1.2.0 and corrected the sorted release-file contract, Pint formatting and strict Playwright locators reported by the 0.44.15 quality gates.
- Changed the player landing page to show **My tickets** first and open the new-ticket form only from a compact action button; removed the redundant limits notice while preserving field counters and server-side validation.
- Converted ticket creation, player replies, staff replies, status actions, assignment, message editing and internal notes to Livewire interactions without full-page reloads.
- Added dedicated scrollable conversation panels that initially show the latest 50 messages and retain the existing **Show previous messages** control.
- Changed the staff-facing `in_progress` label to **Awaiting your reply** while preserving the player-facing **In progress** label.
- Reworked the internal-note composer into a compact expandable control and aligned module settings with the standard administration toggle layout.
- Added Livewire feature regressions, stable browser selectors, no-reload checks, mobile overflow coverage and release-manifest coverage for the new module files.
- The cumulative update line remains based on `0.42.4`; the 0.44.16 package supports direct updates from 0.42.4 through 0.44.15.

## 0.44.15 - 2026-07-29

- Updated bundled `support-tickets` to 1.1.0 and fixed stale PHPUnit, Playwright and Pint contracts from 0.44.14.
- Collapsed the internal-note composer by default while preserving staff-only notes and immutable edit history.
- Split Editor access into independent view, reply/status and internal-note settings; Owner settings remain private and Auditor remains read-only.
- Added limits of 10 tickets per player per day, 100 player messages per day, 300 total messages per ticket and 20 stored revisions per staff message.
- Added cursor pagination for the latest 50 conversation messages and database indexes for player, staff assignment and retention queries.
- Added configurable 6/12/24/36-month or indefinite retention, per-ticket cleanup protection, dry-run/manual cleanup, daily scheduled batch cleanup and an optional manual SQLite `VACUUM` command.
- Expanded RU/EN module documentation and regressions for limits, pagination, permissions, indexes and cleanup safety.
- The cumulative update line remains based on `0.42.4`; the 0.44.15 package supports direct updates from 0.42.4 through 0.44.14.

## 0.44.14 - 2026-07-29

- Added bundled `support-tickets` module 1.0.0 with private player tickets, approved categories and player-facing statuses.
- Owners and administrators can process tickets; editor access is optional and module-scoped; auditors remain read-only.
- Added assignment, internal notes, close/reopen workflow, character limits, duplicate/flood protection and immutable staff-message revision history.
- Added a reusable module administration access registry so future modules can define scoped role access without receiving global `modules.manage`.
- Added RU/EN module documentation, feature/unit regressions and Playwright player/staff/mobile coverage.
- Module artwork is intentionally left for the owner at `modules/support-tickets/assets/module.webp` (512×512 WebP).
- The cumulative update line remains based on `0.42.4`; the 0.44.14 package supports direct updates from 0.42.4 through 0.44.13.

## 0.44.13 - 2026-07-29

- Replaced the obsolete Auditor Promo Codes journal label selector with a route-based locator scoped to the main content area.
- The browser scenario now opens the activation journal, verifies its URL, and confirms that Auditor read-only mode remains active.
- Kept module translations, Auditor permissions, and production navigation unchanged.
- The cumulative update line remains based on `0.42.4`; the 0.44.13 package supports direct updates from 0.42.4 through 0.44.12.

## 0.44.12 - 2026-07-29

- Removed the redundant `$featureAfter !== null` comparison in `GameServerManager`; PHPStan can infer the paired assignment once `$featureBefore` is non-null.
- Kept `treatPhpDocTypesAsCertain` enabled and did not weaken static analysis.
- Replaced Auditor system-tab browser selectors based on exact Russian labels with route-based scoped selectors for Security, System information, and System updates.
- Corrected the obsolete **Сведения о системе** expectation without changing translations, role permissions, Blade navigation, or production behavior.
- The cumulative update line remains based on `0.42.4`; the 0.44.12 package supports direct updates from 0.42.4 through 0.44.11.

## 0.44.11 - 2026-07-29

- Fixed the remaining Laravel Pint `class_attributes_separation` findings in `Admin` and `AdminFactory` by removing duplicate blank lines between methods.
- Stabilized the Auditor Playwright navigation test: collapsible sidebar groups are now opened explicitly and verified through their `open` state before nested links are used.
- Scoped duplicate navigation labels to the administrator sidebar and replaced the ambiguous global **Settings** locator with the existing `data-admin-settings-link` selector.
- Kept production navigation behavior unchanged. Tests no longer depend on cookies, persisted Livewire sidebar state, or `localStorage` leaving a menu group open.
- Access permissions, Auditor visibility, and server-side read-only enforcement are unchanged.
- The cumulative update line remains based on `0.42.4`; the 0.44.11 package supports direct updates from 0.42.4 through 0.44.10.

## 0.44.10 - 2026-07-29

- Removed the redundant **Demo viewer** role and retained one trusted global read-only role: **Auditor**.
- Migrated legacy `read_only` and `demo_viewer` administrator accounts to Auditor and incremented their session version so existing sessions are revoked.
- Preserved the owner-only Auditor assignment rule and the permission-subset guard that prevents administrators from creating accounts with broader access than their own.
- Updated browser-test credentials and coverage to use Auditor. Auditor can inspect every administration section and sensitive journals, while every HTTP and Livewire mutation remains blocked server-side.
- Documented that published Auditor credentials are acceptable only on a separate isolated demonstration stand containing artificial users, test databases, non-secret connection values, and no production data.
- Fixed the remaining Laravel Pint `single_line_empty_body` finding in `AccountCharacterDirectory`.
- Removed the obsolete demo-only redaction, navigation, role, factory, seeder, translation, CSS, and regression branches. Existing security coverage was converted to Auditor coverage rather than weakened.
- Fixed the role-access regression that could reach the two-factor reset throttle while testing two protected accounts; only the remaining protected Auditor account is now exercised.
- The cumulative update line remains based on `0.42.4`; the 0.44.10 package supports direct updates from 0.42.4 through 0.44.9.

## 0.44.9 - 2026-07-29

- Replaced the unsafe global `read_only` role with two explicit system roles: trusted **Auditor** and public **Demo viewer**.
- Added separate view permissions for content, users, administrators, rewards, security, administrator-panel settings, system updates, and queue operations. Auditor and Demo viewer permissions are explicitly enumerated and never inherit future permissions automatically.
- Restricted Auditor and Demo viewer assignment to the owner. Administrators can assign only Administrator or Editor and cannot edit, disable, reset passwords, or reset two-factor authentication for owner-managed auditor/demo accounts.
- Added a general permission-subset rule so a non-owner cannot assign a role containing permissions that the actor does not have.
- Limited Demo viewer to dashboard, content, themes, modules, and safe site/registration/game-account/language settings. Users, administrators, journals, servers, mail, security, administrator-panel settings, system diagnostics, queues, and updates are denied server-side.
- Classified bundled promo-code activation and daily-reward claim journals as sensitive reward history: Demo viewer cannot see their links or open the routes, while Auditor retains read-only access.
- Redacted the public administrator contact address in Demo viewer mode and preserved session-only language switching for shared read-only credentials.
- Migrated legacy `read_only` accounts to the safer `demo_viewer` role and incremented their session version so previously issued sessions are revoked.
- Replaced role-specific system-update checks with explicit `system_updates.view` and `system_updates.manage` permissions.
- Fixed the two PHPStan findings in GameServer feature persistence and feature settings, and restored the expected Pint shape for `AccountCharacterDirectory`.
- Added role-escalation, protected-account takeover, trusted-auditor, safe-demo, PII/infrastructure denial, migration, Livewire mutation, updater, and browser regressions without weakening existing tests.
- The cumulative update line remains based on `0.42.4`; the 0.44.9 package supports direct updates from 0.42.4 through 0.44.8.

## 0.44.8 - 2026-07-29

- Updated the bundled account-theme regressions to the shipped `1.6.2` manifests instead of the obsolete `1.6.0` expectation.
- Removed the unused lower-case `at least one digit.` translation key, preserving the canonical `At least one digit.` key and restoring case-insensitive RU/EN catalog uniqueness.
- Restored Laravel Pint formatting in `AccountCharacterDirectory` and the registration-policy feature tests without weakening or removing assertions.
- Registration policy behavior, account themes, database schema, modules, and user-owned runtime data are unchanged.
- The cumulative update line remains based on `0.42.4`; the 0.44.8 package supports direct updates from 0.42.4 through 0.44.7.

## 0.44.7 - 2026-07-29

- Unified `settings-field` inputs, selects, and textareas with the same explicit one-pixel control border used by standard `form-group` fields, removing the thicker browser-default border visible in Game account settings and other compact forms.
- Added a configurable website username policy: minimum and maximum length plus independent hyphen and underscore allowances. Latin letters and digits remain the safe base character set.
- Added a configurable website password policy: minimum length, letter requirement, mixed-case requirement, digit requirement, and symbol requirement. Safe ranges are validated in the administration panel.
- Applied the saved password policy consistently to new registration, password reset, and player-account password changes. Public forms render matching HTML constraints and localized requirement summaries.
- Added administration, registration, reset, account-password, translation, style, validation, persistence, and audit regressions without weakening existing coverage.
- Updated both bundled account themes to `1.6.2`. The cumulative update line remains based on `0.42.4`; the 0.44.7 package supports direct updates from 0.42.4 through 0.44.6.

## 0.44.6 - 2026-07-29

- Kept the player-facing `Return to city` action visible whenever character rescue is enabled and supported, including while the character is online. Online characters now open an informational modal instructing the player to log out; the submit action is hidden and guarded from submission.
- Preserved the mandatory server-side online-state check and the existing atomic database write, so the UI change does not weaken rescue safety.
- Added feature and shared-runtime regressions for online/offline button state, the online informational modal, and the non-submittable online form path.
- Corrected the read-only role navigation assertion to use the actual Russian label `Журнал действий` without weakening section-access coverage.
- Restored Laravel Pint formatting in `AccountCharacterDirectory`, including the empty constructor body and PHPDoc alignment.
- Updated both bundled account themes to `1.6.1`. The cumulative update line remains based on `0.42.4`; the 0.44.6 package supports direct updates from 0.42.4 through 0.44.5.

## 0.44.5 - 2026-07-29

- Added the system `read_only` administrator role for public demo credentials, audits, and support reviews. The role is selectable in administrator management without a database migration because the existing role column is string-based.
- Read-only administrators can open every registered administration section, including users, other administrator profiles, server settings, modules, audit logs, queue information, and system-update history. Pages render with the existing read-only notice and disabled mutation controls.
- Added a strict server-side mutation guard: non-safe administration requests are rejected before controllers run, own-profile changes are blocked, Livewire GameServer/LoginServer actions explicitly deny read-only users, automatic server-status refresh is disabled, and language switching remains session-only without changing the administrator row.
- Read-only users may inspect owner-only system-update pages, but cannot upload, apply, recover, or discard packages. Viewing a staged update does not create a maintenance recovery secret for this role.
- Added role, permissions, administrator-assignment, all-section visibility, HTTP mutation, Livewire mutation, system-update visibility, language persistence, and dashboard auto-refresh regressions. Existing permission tests were extended rather than weakened.
- The cumulative update line remains based on `0.42.4`; the 0.44.5 package supports direct updates from 0.42.4 through 0.44.4.

## 0.44.4 - 2026-07-29

- Moved character rescue configuration from the separate Additional administration page into the existing GameServer drawer as a compact **Features** tab. The fields now reuse the same `server-form-grid` and `form-group` controls as the General and Miscellaneous tabs.
- The rescue switch follows the maintenance-mode interaction: parameters remain hidden until the feature is enabled. Existing per-server settings, capability checks, validation, cooldowns, audit events, and player behavior are preserved.
- Removed the obsolete Additional sidebar item, controller, request, routes, and views. Versioned deletions remove those files during patch and cumulative updates.
- Fixed sidebar interaction priority so an active or `data-current` item remains blue while hovered instead of falling back to the ordinary dark hover state.
- Replaced the separate-page regressions with Livewire and Playwright coverage for the GameServer Features tab, compact conditional fields, validation tab activation, capability blocking, sidebar item count, and active-hover color persistence. Tests were not weakened.
- Normalized the English `Login servers` label so the existing sidebar-order regression matches the rendered interface.
- The cumulative update line remains based on `0.42.4`; the 0.44.4 package supports direct updates from 0.42.4 through 0.44.3.

## 0.44.3 - 2026-07-29

- Restored `deployment/release-files.json` as the required schema-1 object with a sorted `required_files` list; the 0.44.2 archive accidentally contained only the literal strings `schema` and `required_files`, causing `apply-0.44.2.ps1` to stop before update execution.
- Synchronized the required current apply-script path with `deployment/windows/apply-0.44.3.ps1` and retained the existing PowerShell and PHPUnit release-metadata checks that reject malformed manifests.
- Character rescue runtime logic, database migrations, GameServer capabilities, account themes, and the Additional administration interface are unchanged.
- The cumulative update line remains based on `0.42.4`; the 0.44.3 package supports direct updates from 0.42.4 through 0.44.2.

## 0.44.2 - 2026-07-29

- Reworked the separate GameServer Additional administration page to follow the shared admin design system: the index now reuses server-style summary cards instead of a custom cramped row layout, while the per-server editor stays in the standard settings-form pattern.
- Moved the sidebar entry below Login servers, renamed it to `Additional`, and excluded `/admin/settings/game-server-features*` from the Settings sidebar highlighter so the separate page no longer marks Settings as current.
- Added regression coverage for the new sidebar order, the Additional label, the separate-page heading, the server-card layout, and the client-side rule that keeps Settings inactive on the Additional page.
- Restored Laravel Pint compliance for `AccountCharacterDirectory` after the rescue-related follow-up changes. Runtime rescue logic, migrations, capabilities, and user data remain unchanged.
- The cumulative update line remains based on `0.42.4`; the 0.44.2 package supports direct updates from 0.42.4 through 0.44.1.

## 0.44.1 - 2026-07-28

- Updated `ServerDriverRegistryTest` to include the optional Mobius `characters.x`, `characters.y`, and `characters.z` schema columns introduced for character rescue.
- Reused the existing literal translation key for the unavailable character-rescue state, restoring complete RU/EN translation coverage.
- Fixed Laravel Pint violations in the character directory, rescue service, and feature settings, including removal of an unused import.
- Character rescue runtime behavior, database migrations, driver capabilities, themes, modules, and user-owned runtime data are unchanged.
- The cumulative update line remains based on `0.42.4`; the 0.44.1 package supports direct updates from 0.42.4 through 0.44.0.

## 0.44.0 - 2026-07-28

- Added a separate **Game servers → Features** administration section so optional player services can be configured per GameServer without overloading connection cards.
- Added safe direct offline character rescue with owner-defined destination coordinates, minimum offline delay, per-character cooldown, confirmation modal, atomic ownership/state checks, cache invalidation, UUID operation journal, and redacted audit events.
- Added the optional Mobius `character_rescue` capability. Support is detected from the existing unified schema-profile diagnostics and requires the `characters` coordinate, ownership, offline-state, deletion, and access-level columns.
- Added reusable `game_server_features` storage and a dedicated `CharacterRescueGateway` contract so future character services and non-Mobius schemas can be added without changing the player interface.
- Updated both bundled account themes to 1.6.0 with responsive character rescue actions and the shared account navigation runtime.
- Added Russian/English documentation plus feature, authorization, validation, cooldown, ownership, online-state, idempotency, schema-diagnostics, and direct database-write regressions.
- The cumulative update line remains based on `0.42.4`; the 0.44.0 package supports direct updates from 0.42.4 through 0.43.0.

## 0.43.0 - 2026-07-28

- Prepared the first public release line and reset both the cumulative update baseline and recovery floor to `0.42.4`; the first cumulative package is `KaevCMS-cumulative-update-0.42.4-to-0.43.0.zip`.
- Removed the frozen `docs/history/0.32.10-reference` tree and obsolete `AUDIT-0.30.0.md` / `AUDIT_0.29.0.md` compatibility stubs from release payloads.
- Added versioned deletion coverage and regressions proving the obsolete documentation is removed by patch and cumulative updates without touching `.env`, storage, databases, uploads, modules, themes, or game-server integrations.
- Fixed the Windows update-workflow regression for a freshly reset cumulative baseline: when no superseded pending targets exist, the test now creates a normal current pending marker instead of passing an empty `ToVersion`.
- Database schemas, Composer/npm dependencies, bundled module/theme versions, runtime behavior, and user-owned files are unchanged.

## 0.42.4 - 2026-07-28

- Completed the Laravel Pint formatting of `deployment/release/build-release.php` by adding the required blank line before the non-initial `continue` statement in the ZIP extraction loop.
- Added a mandatory `composer lint` preflight to the official Windows release command so release archives are not created from a Pint-invalid source tree.
- Runtime CMS behavior, database schema, modules, themes, game drivers, updater contracts, and archive format are unchanged.

## 0.42.3 - 2026-07-28

- Applied the final Laravel Pint formatting pass to the unified release builder, its regression test, and the cumulative Web Update package builder.
- Removed only redundant blank lines and added the required blank line before a return statement; runtime behavior and release contracts are unchanged.
- No application logic, database schema, module version, theme, game driver, updater behavior, or archive format changed.

## 0.42.2 - 2026-07-28

- Fixed the unified release-builder regression on Windows by comparing canonical artifact paths instead of raw strings containing mixed `\` and `/` separators after `realpath()` normalization.
- Kept the release-builder contract strict: artifact labels, filenames, existence, deterministic hashes, archive safety, declared deletions, and patch-overlay equality remain fully verified.
- No runtime application behavior, database schema, module version, theme, game driver, updater behavior, or release archive format changed.

## 0.42.1 - 2026-07-28

- Fixed the Web Update package-builder regression fixture by including the required `released_at` metadata and added an explicit negative regression for invalid release dates.
- Stabilized the external-database diagnostics viewport test by switching interface language while the desktop language control is visible, then validating the complete 390/768/800/1024/1440 px matrix. The mobile layout and overflow assertions remain unchanged.
- No runtime application behavior, database schema, module version, theme, game driver, reward flow, or external-database diagnostics contract changed.

## 0.42.0 - 2026-07-28

- Began Public Release Hardening from the confirmed 0.41.8 stable baseline; the cumulative update baseline remains 0.41.6.
- Replaced PowerShell `Env:` provider access for `COMPOSER_DISABLE_NETWORK` with process-scoped `System.Environment` helpers in the official quality and security-audit paths, with regression coverage for set/read/remove/restore behavior.
- Replaced string values stored under the reserved structured-log `exception` key with `exception_class`; diagnostics now have a functional regression proving no exception message or reserved key is emitted.
- Expanded external-database diagnostics browser coverage to 390, 768, 800, 1024, and 1440 px, with actionable overflow diagnostics instead of global overflow hiding.
- Added official GitHub Actions jobs for PHP quality, Windows `quality.ps1`, Windows `browser-quality.ps1`, and release-builder contracts, including failure artifacts for Playwright and Windows diagnostics.
- Added one deterministic release entrypoint that builds full, direct patch, cumulative Web Update, and SHA256 artifacts, rejects unsafe/runtime files and symlinks, applies declared deletions, and proves previous full plus patch matches the target tree.
- Made cumulative package ZIP timestamps and compression deterministic and documented the new release command and 0.41.6 baseline.

## 0.41.8 - 2026-07-28

- Fixed recovery lineage generation when `recovery_floor_version` equals the direct previous version of a newly established cumulative baseline.
- Added a Windows update workflow regression proving the direct previous version is handled separately and is never duplicated in historical recoverable versions.
- Kept the cumulative update baseline at `0.41.6`.

## 0.41.7 - 2026-07-28

- Fixed Pint formatting in `ServerConnectionTester.php`.
- Fixed the System information diagnostics layout at 768 px by switching system cards to one column before long English statuses can widen the document.
- Established `0.41.6` as the new cumulative update baseline.

## 0.41.6 - 2026-07-28

- Updated `guzzlehttp/guzzle` to 7.15.1 and `guzzlehttp/psr7` to 2.13.0, moving the production dependency lock to the security-fixed HTTP client line.
- Deduplicated identical physical external-database probes within one diagnostics refresh while keeping driver-specific schema-profile and capability evaluation separate for every configured server. The in-memory fingerprint is never logged or persisted and the cache is reset after each refresh.
- Removed the System information N+1 schema checks by trusting the already eager-loaded GameServer translations relation; added regressions for a constant translation-query count and shared-connection probe deduplication.
- Fixed the remaining 768 px external-database diagnostics overflow with zero-minimum grid tracks, shrinkable status badges, and a diagnostics-specific responsive breakpoint. No global overflow hiding was introduced.

## 0.41.5 - 2026-07-27

- Fixed the real mobile System information overflow in the administrator header. The generic `.admin-user span` rule had higher specificity than the mobile account-copy hiding rule, leaving the administrator name, email, role, and chevron visible at 390 px.
- Increased the mobile selector specificity to `.admin-user .admin-account-copy` and `.admin-user .admin-account-chevron`; restored the browser regression to the original `documentElement` width check and added direct assertions that both header elements are hidden.
- Reverted the incorrect 0.41.4 scrollbar-gutter workaround. External database diagnostics, migrations, driver profiles, stored snapshots, modules, and game-database schemas were not changed.

## 0.41.4 - 2026-07-27

- Corrected the mobile System information browser regression on Windows: the assertion now measures the body content box, avoiding a false horizontal-overflow result caused by the classic vertical scrollbar gutter in `documentElement` metrics.
- Removed the temporary `overflow-x: clip` containment so genuine horizontal content overflow remains visible to the browser regression instead of being hidden.
- Synchronized the release header and documentation version that were missed in the 0.41.3 packaging metadata. Diagnostics behavior, migrations, driver profiles, stored snapshots, modules, and game databases were not changed.

## 0.41.3 - 2026-07-27

- Isolated the external-database diagnostics feature fixture from the GameServer created by the clean-install migration.
- Added the final System information mobile width containment pass for long diagnostic values. Diagnostics behavior, migrations, driver profiles, stored snapshots, modules, and game databases were not changed.

## 0.41.2 - 2026-07-27

- Fixed the remaining external-database diagnostics regressions: the feature fixture now creates one explicit Mobius LoginServer/GameServer pair, and MySQL tester mocks match the intentional single PDO server-version read.
- Removed redundant scalar checks from `MySqlSessionQueryTimeout`, resolving the remaining PHPStan errors without changing timeout selection or connection behavior.
- Fixed mobile System information overflow at the PHP-extension cards by allowing long extension names and status labels to wrap inside the card instead of expanding the document. The diagnostics migration, driver profiles, stored snapshot format, modules, and game-database schemas were not changed.

## 0.41.1 - 2026-07-27

- Corrected the diagnostics route permission contract, translation keys, MySQL server-version reuse, test fixtures, Pint formatting, and the first mobile containment pass discovered while validating 0.41.0. The diagnostics schema and stored snapshot format were not changed.

## 0.41.0 - 2026-07-27

- Extended the existing LoginServer/GameServer monitor and connection tester with a persisted, redacted diagnostic snapshot: database availability, last successful connection, safe error class/time, last successful `SELECT 1` latency, active schema profile, driver capabilities, and required/optional table state.
- Added data-driven LoginServer and GameServer capability metadata, automatic Mobius legacy/modern game-schema detection, and explicit safe domain error classes for unavailable drivers and incompatible schemas.
- Added a manual diagnostics action and mobile-safe cards to System information. The page and copied support report exclude hosts, database names, usernames, passwords, DSNs, absolute paths, raw SQL, and exception messages.
- Preserved the last successful schema/capability snapshot across temporary network failures, reset stale diagnostics when connection settings change, and reused the existing monitor instead of introducing another scheduled reconciliation mechanism.
- Added Russian/English operator guidance plus PHPUnit and Playwright regressions for schema profiles, optional capabilities, safe failure persistence, secret redaction, route permissions, and the mobile diagnostics interface. Bundled modules, themes, reward states, and game-database schemas were not changed.

## 0.40.3 - 2026-07-27

- Fixed the remaining mobile administrator overflow at its real source: the persisted sidebar wrapper no longer passes the full horizontal menu width into the mobile grid track. The menu remains internally scrollable while the document width stays within the viewport. Reward behavior, database schemas, module versions, and migrations were not changed.
## 0.40.2 - 2026-07-27

- Fixed the remaining Laravel Pint `not_operator_with_successor_space` violation in `BrowserTestSeeder`.
- Constrained the mobile reward-queue card hierarchy to the viewport and made long diagnostic codes wrap inside their card, preventing document-level horizontal overflow without hiding overflowing content globally. Reward states, database schemas, module versions, migrations, and delivery behavior were not changed.

## 0.40.1 - 2026-07-27

- Fixed Laravel Pint formatting in the browser-test seeder and reward-delivery journal test imports.
- Updated reward journal feature regressions to validate the localized server label and the actual split item-name/amount markup instead of obsolete concatenated text.
- Replaced the stale release source assertion with the current stable `rewards.queue.journal.title` translation key.
- Made the reward queue Playwright scenario select the intended `review` row through an explicit `data-status` contract, removed the hard-coded English Adena snapshot from the browser fixture, and aligned the web-inventory shell scenario with the reserved-review fixture state. Runtime reward states, database schemas, dependencies, module versions, and delivery behavior were not changed.

## 0.40.0 - 2026-07-27

- Audited the existing reward pipeline and documented the actual boundary between KaevCMS delivery states (`pending`, `review`, `queued`, `failed`) and external GameServer consumer states (`pending`, `processing`, `delivered`, `failed`). `queued` now explicitly means that the immutable payload exists in `kaev_reward_queue`, not that the character already received the items.
- Added a unique `operation_uuid` to every web-inventory grant, backfilled existing grants, and enriched reward audit records with the operation UUID, GameServer ID, status transition, and normalized item composition.
- Hardened idempotency: a repeated grant key or transfer request token is accepted only when its user, server, source, target character, and item payload match the original operation. Conflicting or cross-user replays are rejected without creating another queue payload.
- Centralized queue diagnostics and RU/EN messages for missing or invalid schemas, unavailable GameServer databases, uncertain writes, confirmed failures, empty payloads, and immutable payload conflicts.
- Improved the administrator reward queue journal with status totals, live per-GameServer capability diagnostics, operation UUIDs, localized reasons and recommended actions, item icons/names, and a mobile card layout.
- Improved Promo Codes and Daily Rewards journals with operation UUIDs, GameServer IDs, item icons, localized names, amounts, and web-inventory states. Both bundled modules are now 1.3.0.
- Added SQL examples and Russian/English operator runbooks for safely handling `review`, CMS `failed`, and external consumer `failed` rows without blind delivery or deletion, plus PHPUnit and Playwright regressions for status transitions, replay conflicts, audit correlation, journal details, and mobile layouts.

## 0.39.2 - 2026-07-27

- Fixed PHPStan `nullCoalesce.expr` errors in `RecoverGameAccountCreationCommand` by using the non-null `UserGameAccount` relations declared by the model contract directly. Game-account recovery behavior, database schemas, dependencies, modules, themes, game drivers, item catalogs, and reward delivery were not changed.

## 0.39.1 - 2026-07-27

- Fixed the remaining Laravel Pint `class_attributes_separation` violation in `UserManagementTest` by removing the extra blank line between test methods. Runtime behavior, database schemas, dependencies, modules, themes, game drivers, and the 0.39.0 game-account reliability implementation were not changed.

## 0.39.0 - 2026-07-27

- Replaced the delete-on-error game-account flow with durable `pending`, `active`, and `failed` operations identified by a unique UUID.
- Added idempotent retry and post-write LoginServer verification so a committed INSERT followed by a network timeout is activated without a second INSERT, while known duplicate-key races and pre-existing accounts are never linked automatically.
- Added encrypted driver proof storage without plaintext passwords, operation leases, attempt/error diagnostics, quota-safe state handling, and active-only player/admin account visibility.
- Added the `kaevcms:game-accounts-recover` command for stale-pending diagnostics, verification-only recovery, and explicit safe retry; no permanent background reconcile was scheduled.
- Added Russian and English operator runbooks, a database migration for operation state, and PHPUnit regression coverage for timeout, duplicate, foreign-account, retry, quota-race, and stale-operation scenarios. Dependencies, bundled module/theme versions, item catalogs, and reward delivery were not changed.

## 0.38.2 - 2026-07-27

- Corrected the release metadata regression to validate legacy removals across the accumulated `deletions.json` history instead of requiring every historical path to be duplicated in the current release delta.
- Preserved the stricter current-release check that the immediately previous apply script is removed. Runtime code, database schemas, dependencies, modules, themes, game drivers, item catalogs, and reward delivery were not changed.

## 0.38.1 - 2026-07-27

- Fixed Windows PowerShell 5.1 parsing of update-contract duplicate checks by validating each contract list separately with explicit array semantics.
- Removed the hidden carriage-return character from the `deployment/release-files.json` regression fixture path, restoring `update-workflow.ps1` execution on Windows.
- Corrected the literal translation regression so grouped PHP keys such as `rewards.transfer.review` are resolved from `lang/{locale}/rewards.php` instead of being incorrectly required in the JSON catalogue.
- Added a stable `data-testid` for the Promo Codes activation input and updated PHPUnit/Playwright coverage; Promo Codes is now 1.2.2. Runtime database schemas, dependencies, game drivers, item catalogs, themes, and reward delivery were not changed.

## 0.38.0 - 2026-07-27

- Added validated `release.json`, release-file, and Windows-update contracts as the authoritative source for release lineage, apply-script names, dependency fingerprints, runtime directories, cleanup history, and recovery behavior.
- Reworked Windows update and package-builder regressions around executable, data-driven contracts instead of brittle source-text snippets and nested PowerShell quoting.
- Added an explicit administrative route access registry with complete route classification coverage while preserving the existing `ModulesView` read-only and `ModulesManage` write model.
- Replaced layout-coordinate browser assertions with semantic test IDs, accessibility state, editable controls, and real user interactions.
- Added Russian/English key parity tests for bundled Daily Rewards and Promo Codes, introduced stable failure enums and translation keys for module claims, promo activation, and reward transfers, and moved reward-transfer messages into dedicated language files.
- Added Russian and English operator runbooks for interrupted Windows updates, unavailable external LoginServer/GameServer databases, uncertain reward-queue results, and safe support diagnostics. Daily Rewards is now 1.2.2 and Promo Codes is now 1.2.1. Database schemas, dependencies, game drivers, item catalogs, themes, and user-facing capabilities were not changed.

## 0.37.4 - 2026-07-27

- Fixed the remaining Laravel Pint `single_line_empty_body` violation in the standalone account-theme contract.
- Replaced the stale release assertion that required the shared `public/assets/account` runtime to be deleted with an explicit preservation assertion.
- Updated Windows and cumulative update metadata for the 0.37.3 to 0.37.4 hotfix. Runtime features, database schemas, modules, themes, dependencies, game drivers, item catalogs, and reward delivery were not changed.

## 0.37.3 - 2026-07-27

- Fixed the standalone account-theme contract formatting required by Laravel Pint.
- Prevented the Windows updater from deleting the intentional shared account navigation runtime under `public/assets/account`.
- Corrected the updater regression so the shared runtime is preserved while obsolete per-theme navigation copies remain removable. Runtime features, database schemas, modules, themes, dependencies, game drivers, item catalogs, and reward delivery were not changed.

## 0.37.2 - 2026-07-27

- Fixed the account navigation regression test so the intentional shared core runtime under `public/assets/account` is no longer classified as a legacy asset.
- Replaced the remaining simple double-quoted test literal with a Pint-compatible single-quoted literal.
- Reformatted the standalone account-theme contract to satisfy Pint while preserving clean-install bootstrap coverage.

## 0.37.1 - 2026-07-27

- Fixed clean installation, Composer package discovery, PHPStan bootstrap, and browser-test bootstrap after the account-theme navigation runtime was moved to the shared core asset.
- Account themes now validate only theme-owned public CSS instead of requiring the removed per-theme `assets/js/navigation.js` file.
- Added release regression coverage for the shared account runtime contract. Database schemas, module versions, theme versions, dependencies, game drivers, item catalogs, and reward delivery were not changed.

## 0.37.0 - 2026-07-26

- Split the 4,000-line administration stylesheet into seven ordered assets for base tokens, layout, content, infrastructure, shared components, extensions, and catalogues while preserving the previous rule order and declarations exactly.
- Replaced two identical account-theme navigation scripts with one versioned shared runtime under `public/assets/account/js/navigation.js`; updated Luxury and Kaev Aurelia Account to 1.5.0 with a `cms_min` of 0.37.0.
- Reformatted the Daily Rewards stylesheet into maintainable source form without changing selectors or declarations.
- Added PHPUnit, Playwright, release-package, and Windows updater regressions for the split asset stack, shared account runtime, and removal of obsolete duplicated files.
- Corrected the README and changelog baseline so the user-verified 0.36.7 parser hotfix is represented. Composer/npm locks, database schemas, module versions, game drivers, item catalogs, and reward-delivery schemas were not changed.

## 0.36.7 - 2026-07-26

- Fixed the PowerShell parser error in `deployment/windows/tests/update-workflow.ps1` by constructing the historical hard-coded recovery-list probe without invalid nested quote escaping.
- Restored `quality.ps1` execution without changing runtime code, database schemas, modules, themes, game drivers, item catalogs, or reward delivery.

## 0.36.6 - 2026-07-26

- Replaced manually maintained interrupted-update version arrays with recovery lineage derived from the versioned `deployment/updates/deletions.json` history.
- Added PowerShell regression coverage proving that the original `0.34.9 -> 0.35.0` pending marker remains recoverable by a future hotfix without editing an exact expected array.
- Updated release metadata checks to validate the data-driven recovery contract instead of brittle source-text snapshots. Runtime functionality remains unchanged.

## 0.36.5 - 2026-07-26

- Replaced the order-dependent module catalogue browser assertion with direct one-column grid and full-row width checks.
- Kept stable `data-module-id` selectors and fixed 124×124 module artwork assertions. Runtime functionality remained unchanged.

## 0.36.4 - 2026-07-26

- Fixed the Windows update workflow regression test so it resolves the current apply script from `VERSION` instead of referencing a deleted previous-release script.
- Added stable `data-module-id` attributes to module catalogue rows and updated Playwright selectors to prevent both module locators from resolving to the same card.
- No runtime, database, module schema, game driver, or asset-path behavior changed.

## 0.36.3 - 2026-07-26

- Fixed the Windows updater runtime-directory contract so it recreates the canonical `public/uploads/game-assets/items` parent directory together with `common` and `servers`.
- Hardened the module catalogue Playwright regression by selecting each module through its exact heading instead of broad descendant text matching, while preserving the required single-column row layout.
- Runtime module behavior, database schemas, item resolution, Daily Rewards, Promo Codes, themes, and dependencies were not changed.

## 0.36.2 - 2026-07-26

- Fixed shared item icon lookup under `public/uploads/game-assets/items/common` and server overrides under `items/servers/{server_id}`.
- Stabilized Daily Rewards dialog dismissal so dragging from inside the dialog to the backdrop cannot close it accidentally.
- Added item previews and aligned reward fields in the Promo Codes administration form; updated Promo Codes to 1.2.0 and Daily Rewards to 1.2.1.
- Added the documented empty game-asset directory structure and repaired recovery from interrupted 0.35.0, 0.36.0, and 0.36.1 Windows updates.

## 0.36.1 - 2026-07-26

- Fixed `ReleaseMetadataTest` so the shared-hosting builder assertion treats `$path` as literal source text instead of interpolating an undefined PHP variable.
- Rewrote the new game-asset release assertions as Pint-compatible single-quoted literals. Runtime game assets, Daily Rewards, module dialogs, database schemas, modules, themes, and dependencies were not changed.

## 0.36.0 - 2026-07-26

- Consolidated all owner-managed game images under `public/uploads/game-assets`. Shared item icons may use either numeric item IDs or catalog icon keys; server-specific overrides remain under `items/servers/{server_id}`.
- Removed the obsolete `public/game-assets` tree, profile-specific item image folders, `common` image folders, and chronicle-specific character avatar directories. Character avatars now use one shared `{race}/{gender}/{archetype}` hierarchy with optional server overrides.
- Updated the Web Installer, Windows setup/update workflows, release builders, documentation, and regression tests for the canonical protected upload paths. Release archives still exclude all owner uploads.
- Improved the Daily Rewards day editor: item ID and amount inputs are aligned, the modal action is named **Apply**, unsaved changes remain visible, bulk fill requires confirmation, and navigation warns before discarding edits.
- Restyled Daily Rewards and Promo Codes result dialogs as theme-specific frosted-glass surfaces while retaining opaque browser fallbacks and accessible contrast.
- Removed pre-release `l2forge:*`, `l2cms:*`, and `cms:about` runtime aliases plus the old administration-menu localStorage migration. The historical rebrand database migration remains intact.
- Updated Daily Rewards to 1.2.0 and both player-account themes to 1.4.1. Composer/npm dependencies, database schemas, game drivers, item catalog data, and reward-delivery schemas were not changed.

## 0.35.0 - 2026-07-26

- Rebuilt the Modules catalogue as a compact single-column list inspired by the News catalogue. Module names and descriptions now lead each row, artwork uses a fixed 124×124 preview, metadata and capabilities stay readable, and status/actions remain aligned on the right.
- Rebuilt both public-theme and player-account-theme catalogues with the same stable row system. Theme previews use a fixed 230×130 viewport, so different source image dimensions no longer change card height or button placement.
- Added shared responsive administration catalogue styles and PHPUnit/Playwright regressions for one-item-per-row layout, fixed preview dimensions, and left-to-right copy/preview order.
- Composer/npm dependencies, database migrations, module versions, account-theme versions, game drivers, item catalog data, external game-image paths, and reward-delivery schemas were not changed.

## 0.34.9 - 2026-07-26

- Fixed PHPStan analysis of validated module artwork metadata by removing redundant null-coalescing operations after `getimagesize()` succeeds.
- Updated Daily Rewards and reward-result Playwright expectations to follow the current administration action label and server-bound Web Inventory query parameter.
- Corrected the release metadata CSS selector assertion for module artwork without changing runtime behavior.
- Composer/npm dependencies, database migrations, module versions, account-theme versions, game drivers, item catalog data, external game-image paths, and reward-delivery schemas were not changed.

## 0.34.8 - 2026-07-26

- Added optional auto-discovered `assets/module.webp` artwork for trusted modules. The administration catalogue now serves validated 512×512 WebP images through an authenticated route and keeps the existing letter placeholder when artwork is absent or invalid.
- Rebuilt the Daily Rewards administration editor as a visual month calendar. Each day opens in a modal dialog, shows item names, icons and amounts, supports adding/removing rewards and copy actions, and preserves claimed-day immutability.
- Added one shared player-account operation dialog for module results. Daily Rewards claims and Promo Codes activations now show success or failure in the same accessible modal, including immutable reward names, item icons, amounts and a Web Inventory action.
- Updated bundled Promo Codes and Daily Rewards modules to 1.1.0 and both account themes to 1.4.0. No application or module database migrations were added; game drivers, item catalog data and reward-delivery schemas were not changed.

## 0.34.7 - 2026-07-26

- Fixed the release metadata regression that incorrectly rejected owner-provided game item images stored in `public/game-assets`.
- Local development and installed sites may now keep external WebP, PNG, JPG, JPEG, GIF, or SVG game assets without causing PHPUnit to fail.
- Release safety remains unchanged: cumulative and shared-hosting builders still exclude binary game images while preserving README and `.gitkeep` files.
- Runtime code, database migrations, module versions, game drivers, item catalogs, and reward-delivery schemas were not changed.

## 0.34.6 - 2026-07-26

- Fixed language switching from account module pages such as Daily Rewards: `/modules/...` is no longer transformed into the nonexistent `/{locale}/modules/...` route.
- Module-page language changes now return to the localized player-account overview and intentionally discard module-specific query parameters such as `calendar` and `account`.
- Added PHPUnit and Playwright regressions for switching language from `/modules/daily-rewards?calendar=1&account=2`.
- Database migrations, module versions, game drivers, item catalogs, external image paths, and reward-delivery schemas were not changed.

## 0.34.5 - 2026-07-26

- Converted all remaining non-interpolated double-quoted literals in `AccountNavigationTest` to Pint-compatible single-quoted strings; the previous hotfix changed only the final assertion while ten earlier literals still violated `single_quote`.
- Replaced the narrow literal regression with a token-based release-tree check that rejects any simple double-quoted string in the affected test file.
- Runtime code, database migrations, modules, game drivers, item catalogs, external image paths, and reward-delivery schemas were not changed.

## 0.34.4 - 2026-07-26

- Corrected the remaining Pint `single_quote` violation in `AccountNavigationTest`; the 0.34.3 release notes described this fix, but its final archive still contained the old double-quoted literal.
- Added release metadata and package history for the corrected hotfix without changing runtime code, database migrations, modules, game drivers, item catalogs, external image paths, or reward-delivery schemas.

## 0.34.3 - 2026-07-26

- Fixed the Pint `single_quote` violation in the account-navigation regression without weakening its literal Blade assertion.
- Updated stale account overview and Playwright expectations to follow the approved top-right account menu and its `.account-profile-balance` coin block.
- Removed the unused `characters.createDate` requirement from `MobiusGameSchemaInspector`; the driver never selects or filters by this column, so compatible Mobius schemas and existing statistics fixtures are no longer rejected before runtime queries.
- Restored the complete Windows apply-script release contract required by installer, updater, shared-hosting, game-item catalog, and recovery regression checks.
- Composer/npm dependencies, database migrations, module versions, item catalogs, external image paths, and reward-delivery schemas were not changed.

## 0.34.2 - 2026-07-26

- Removed the account-settings block from the left sidebar so the sidebar contains only game-facing navigation and module-provided sections.
- Restored one consistent top-right avatar menu on desktop and mobile, moved the future coin balance into that dropdown, and kept donation navigation reserved for a future module-provided sidebar entry.
- Split account information/avatar management and KaevCMS password security into dedicated `/account/profile` and `/account/security` pages with shared tabs and clearer scope messaging.
- Added default/localized route, menu-placement, page-separation, release-artifact, and Playwright regression coverage.
- Composer/npm dependencies, database migrations, module versions, game drivers, item catalogs, external image paths, and reward-delivery schemas were not changed.

## 0.34.1 - 2026-07-26

- Replaced the oversized player-account welcome hero with a compact overview heading and a contextual first-game-account onboarding state shown only when the player has no game accounts.
- Consolidated account navigation: the desktop sidebar user block now opens the account menu, the top-right avatar menu is mobile-only, and the obsolete “Player profile” wording was replaced with clear account settings and security actions.
- Rebuilt the profile page as “Account settings” with account information, avatar management, and an in-session KaevCMS password-change form that is explicitly separated from game-account passwords.
- Added current-password verification, shared registration-strength rules, same-password rejection, remember-token rotation, session regeneration, audit logging, optional password-change email notification, throttling, and sensitive-input protection.
- Added regression coverage for compact onboarding, account-menu placement, default/localized password changes, incorrect and reused passwords, audit records, and password-field non-flashing.
- Composer/npm dependencies, database migrations, module versions, game drivers, item catalogs, external image paths, and reward-delivery schemas were not changed.

## 0.34.0 - 2026-07-26

- Renamed the canonical L2J Mobius GameServer driver identifier from `l2j_mobius_ct0_interlude` to `l2j_mobius`; the LoginServer and GameServer registries now intentionally use the same Mobius key in their separate contexts.
- Replaced the misleading “all chronicles” GameServer label with `L2J Mobius` and documented automatic legacy (`karma`) and modern (`reputation`) schema detection without claiming untested chronicle coverage.
- Centralized Mobius driver identifiers in `ServerDriverRegistry` and removed scattered runtime string comparisons from the driver resolver, player-account queries, Livewire defaults, and external account gateway.
- Unified administration and runtime schema validation through `MobiusGameSchemaInspector::requirements()`. Missing required `characters` or `clan_data` columns are now rejected before statistics or character queries run; incomplete `heroes` and `castle` tables remain optional capabilities.
- Added a database migration for the previous GameServer identifier plus regression tests for migration safety, legacy/modern profile detection, dual-column chronicle selection, missing required columns, and optional capabilities.
- Composer/npm dependencies, module versions, item catalogs, external image paths, and reward-delivery schemas were not changed.

## 0.33.9 - 2026-07-26

- Fixed `ReleaseMetadataTest` so literal `$icon` and `$profile` fragments are asserted without PHP variable interpolation.
- Restored immutable reward-name snapshots: a stored `item_name` now keeps priority over a later catalog name, while rewards without a stored name still resolve through the chronicle catalog.
- Added `RefreshDatabase` to the integer-GameServer catalog test so the real `login_servers` and `game_servers` schema is available instead of failing before the assertion.
- Removed the extra blank lines reported by Laravel Pint in the release metadata and character appearance tests.
- Composer/npm dependencies, database migrations, module versions, JSON catalogs, external image paths, and reward issuance logic were not changed.

## 0.33.8 - 2026-07-26

- Removed development-only item catalog generation commands, parsers, and their dedicated tests from the production CMS. The four ready runtime JSON catalogs remain unchanged.
- Updated Windows and Web Updater deletion metadata so upgrading from 0.33.7 removes the obsolete generation files automatically without touching external item icons or character avatars.
- Reworded public documentation and regression coverage around the final runtime architecture: profile plus item ID resolves a catalog name and icon key, while all image binaries stay external to release archives.
- Composer/npm dependencies, database migrations, module versions, item catalog data, reward logic, and external image paths were not changed.

## 0.33.7 - 2026-07-26

- Added static item profiles for Classic (19,791 IDs), High Five (19,198 IDs), and Shine Maker (19,790 IDs), alongside the existing Interlude catalog (9,208 IDs).
- Added robust chronicle normalization for free-text server names such as `Classic 3.5 Tales Untold`, `High Five`, `h5`, `shineMaker`, and `Shine Maker`. Integer GameServer IDs continue to resolve the stored chronicle instead of falling back to the default profile.
- Added a shared external icon pool at `public/game-assets/items/{icon_key}.webp` so one physical file can serve several profiles. The 0.33.6 path `public/game-assets/items/{profile}/{icon_key}.webp` remains a fallback and is never deleted by updates.
- Extended the empty external character-avatar hierarchy to Interlude, Classic, High Five, and Shine Maker. Full, patch, cumulative, and shared-hosting archives still exclude all game image binaries.
- Added profile-isolation, shared-icon, legacy-path, integer-server, release-packaging, and existing-module regression coverage. Composer/npm dependencies, CMS migrations, module versions, and reward-delivery schemas were not changed.

## 0.33.6 - 2026-07-26

- Added a versioned Interlude item catalog that maps 9,208 positive item IDs to catalog English names and original icon keys without adding database tables or an item-management panel.
- Extended `GameItemCatalog` and `GameAssetUrlResolver` so Promo Codes, Daily Rewards, Web Inventory, and reward journals resolve chronicle-aware names and original icon filenames. Manual RU/EN and per-server numeric overrides keep priority.
- Standard item icons are loaded from the separate `public/game-assets/items/interlude` pack. Character avatars may be placed under `public/game-assets/characters/interlude`; uploaded server-specific and common overrides under `public/uploads/game-assets` keep priority.
- KaevCMS full, patch, cumulative, and shared-hosting archives intentionally exclude image binaries under `public/game-assets`, while preserving the folder hierarchy and instructions.
- Added catalog, module integration, external icon, external avatar, packaging, and fallback regression coverage.
- Composer/npm dependencies, database migrations, module versions, and reward-delivery schemas were not changed.

## 0.33.5 - 2026-07-25

- Fixed the Daily Rewards Playwright check to validate the exact Web Inventory row containing Adena × 250 000. The previous assertion searched the entire page and failed correctly when Promo Codes and Daily Rewards produced two separate Adena rows.
- Removed the extra blank lines introduced in `BrowserTestSeeder.php` and `build-shared-hosting-package.php`, restoring Laravel Pint compliance without changing runtime behavior.
- Added safe Windows recovery for an interrupted 0.33.4 update from the still-committed 0.33.3 installation. Composer/npm dependencies, database migrations, module versions, Promo Codes, and runtime Daily Rewards logic were not changed.

## 0.33.4 - 2026-07-25

- Optimized Daily Rewards account loading by selecting only enabled calendars for the current year and month directly in SQL, so historical calendars, days, and items are no longer loaded and filtered in PHP.
- Refactored Daily Rewards claim preparation so one normalized `RewardGrantItem` list is used both for the immutable claim snapshot and the Web Inventory grant, preventing the two representations from drifting apart. Daily Rewards is now 1.0.3.
- Added a Playwright browser scenario for claiming the current daily reward and verifying the resulting Web Inventory items.
- Added a clear post-install instruction to remove the public `/install` directory. HTTP installation remains allowed; installer secrets and signed update packages were deliberately not added.
- Removed deployment-only regression scripts from newly built shared-hosting production packages while keeping all runtime installer and updater files. Composer/npm dependencies and database migrations were not changed.

## 0.33.3 - 2026-07-25

- Fixed the Daily Rewards claimed-day validation path by importing Laravel's `ValidationException`; attempts to edit a claimed day now return the intended form error instead of a class-not-found exception.
- Updated Daily Rewards to 1.0.2 and added safe recovery for an interrupted 0.33.2 Windows update. Promo Codes, its migrations, routes, views, and data model were not changed. Composer/npm dependencies and database migrations were not changed.

## 0.33.2 - 2026-07-25

- Fixed the Daily Rewards calendar form so every configured GameServer is available, including servers that do not currently reference a LoginServer.
- Removed the per-calendar time-zone field. Daily Rewards now uses the time zone from the main KaevCMS settings for calendar display, day boundaries, and claims; existing calendars follow the current setting automatically.
- Changed the administration module catalogue to a maximum of two cards per row on desktop and one card per row on narrower screens.
- Updated Daily Rewards to 1.0.1 and added regression coverage for standalone GameServer selection, automatic time-zone use, and the module catalogue layout. Composer/npm dependencies and database migrations were not changed.

## 0.33.1 - 2026-07-25

- Fixed both theme catalogues to use the shared administration card-row component while preserving the one-theme-per-row layout, preview, metadata, and right-aligned activation action. Added regression coverage for both public-site and player-account theme lists.
- Corrected the Daily Rewards lifecycle regression so disabled module routes are checked as authenticated account/owner requests. The module remains protected by its runtime middleware and disabling it still preserves calendars, claims, and inventory grants.
- Isolated Web Updater feature tests from the live installed-version marker. Automated tests now use a temporary marker synchronized with the source release, so an in-progress update can run PHPUnit before the real installed version is committed.
- Limited the core literal-translation audit to bundled first-party themes. Third-party themes and modules keep their own translation catalogues and can no longer break the KaevCMS core RU/EN quality gate with extension-specific strings.
- Fixed the Daily Rewards test PHPDoc formatting reported by Laravel Pint. Composer/npm dependencies, application database migrations, and module database migrations were not changed.
- The Windows hotfix updater accepts both a completed 0.33.0 installation and the preserved 0.32.20 state from an interrupted 0.33.0 update, allowing the failed update shown by the quality gate to resume safely as 0.33.1.

## 0.33.0 - 2026-07-25

- Added the bundled `daily-rewards` module 1.0.0. Owners can create a separate monthly calendar for each game server, configure one or more item rewards for every real calendar day, copy rewards between days, publish or disable the calendar, and review an immutable claim journal.
- Added the player Daily Rewards calendar. Every eligible game account may claim only the current day once; missed days expire, and all items are granted transactionally to the server-bound KaevCMS Web Inventory with an idempotent request token and database uniqueness protection.
- Locked claimed day rewards and the calendar time zone against retroactive changes while preserving snapshots of the exact items granted. Disabling the module removes its runtime pages without deleting calendars, claims, or Web Inventory grants.
- Changed both public-site and player-account theme catalogues in the administration panel to one theme per horizontal row, with preview and metadata on the left and the activation action aligned on the right. This prevents large theme collections from becoming stretched auto-fit cards.
- Added RU/EN translations, responsive module assets, module documentation, and regression coverage for module lifecycle, calendars, permissions, claims, idempotency, server/account isolation, immutable history, localization parity, and the theme list layout.
- Composer/npm dependencies were not changed. Four module-owned CMS database migrations were added.

## 0.32.20 - 2026-07-24

- Unified the standard and split/shared-hosting public front controllers. `public/index.php`, `public/install/index.php`, and `.htaccess` now detect `kaevcms-path.php` at runtime, so the same cumulative payload remains valid whether the public directory is named `public_html`, a technical domain, or another provider-specific value.
- Fixed cumulative shared-hosting updates that could replace the split Apache rules with the standard `.htaccess` and redirect an installed site to `/install/` with `KaevCMS web installer is missing.` The unified Apache rules protect `kaevcms-path.php` while remaining valid for standard deployments.
- Protected the runtime split locator `public/kaevcms-path.php` from update-package replacement or deletion. The generated `bootstrap/kaevcms-public-path.php` remains the source of the actual public path for Laravel CLI and Web Updater operations.
- Added executable regressions for standard layout plus split layouts named `public_html` and `a860dbbcc70b.hosting.myjino.ru`, including real public and installer entrypoint execution after an update-style replacement.
- Composer/npm dependencies and database migrations were not changed.

## 0.32.19 - 2026-07-24

- Fixed the MySQL extension-diagnostics Feature test so it no longer changes PHPUnit's active database connection from in-memory SQLite to MySQL. The test now changes only the reported driver metadata and restores it after the assertion, preventing the initial `Unknown database ':memory:'` failure and the following SQLite nested-transaction cascade.
- Restored the release metadata contract by adding all critical Web Installer, shared-hosting, Web Updater, migration, and administration files to the current Windows apply script completeness check.
- The shared-hosting package builder, Composer/npm dependencies, database migrations, and application behavior were not changed.

## 0.32.18 - 2026-07-24

- Fixed cumulative shared-hosting package compatibility with older supported Web Updaters. Clean-install/runtime control files rejected by the 0.32.x legacy path policy (`core/.env.example`, `public/uploads/.gitignore`, and `public/uploads/.htaccess`) are no longer shipped as payload targets.
- The cumulative package builder now validates every target against the oldest supported 0.32.x Web Updater policy before creating an archive, preventing packages that advertise a wide version range but are rejected before self-update.
- Added idempotent runtime preparation of the public uploads protection files. Missing `.gitignore` and Apache `.htaccess` protection are recreated after an older shared-hosting installation updates, while existing custom files are preserved.
- System Information now marks `pdo_sqlite` as required only when the CMS database actually uses SQLite. On MySQL/MariaDB installations it is shown as optional; `pdo_mysql` remains required for the CMS/game database integrations.
- Added regression coverage for upload protection, MySQL extension diagnostics, and cumulative-package legacy compatibility. Composer/npm dependencies and database migrations were not changed.

## 0.32.17 - 2026-07-24

- Web Installer now creates and verifies the dedicated upload directories for news, pages, site settings, account avatars, and game assets instead of checking only the common `public/uploads` parent.
- System Information now creates missing known upload directories before testing real PHP-FPM write access, eliminating false `Not writable` reports when the writable parent exists but the feature directory has not been used yet.
- Added regression coverage for installer-created upload directories and runtime diagnostics that recover missing upload directories without weakening permission checks.
- Expanded Russian and English installation guides with changing the site IP/domain/protocol after installation, `APP_URL`, `APP_FORCE_HTTPS`, `SESSION_SECURE_COOKIE`, nginx `server_name`, cache clearing, `419 Page Expired`, upload-permission repair, and draft-news troubleshooting.
- Composer/npm dependencies and database migrations were not changed.

## 0.32.16 - 2026-07-24

- Web Installer no longer blocks database checks or owner creation on HTTP. The same clear, non-blocking warning is shown for every unencrypted address, including public hosts, private IPs, and local test environments.
- HTTP installations preserve the detected `http://` site URL and non-secure session-cookie mode; HTTPS detection, trusted reverse-proxy handling, rate limiting, session-bound resume locks, and the final security review remain intact.
- Updated Russian and English shared-hosting, installation, security, Web Installer, and Ubuntu VDS documentation. The VDS guide now explains that Certbot may be skipped when no domain or A record exists and documents direct installation by IP.
- Added installer and release regressions proving that HTTP shows a warning without disabling database or final installation actions. Composer/npm dependencies and database migrations were not changed.

## 0.32.15 - 2026-07-23

- Corrected the remaining 0.32.14 release-gate blockers reported by the complete Windows audit: two Laravel Pint formatting issues and one unreachable PHPStan branch in the VDS CLI updater.
- `InstallSystemUpdateCommand` now handles the documented `getcwd(): string|false` result directly, and the CLI updater Feature test follows the required trait-to-method spacing.
- Application behavior, Composer/npm dependencies, database migrations, deployment layouts, and update-package semantics were not changed.

## 0.32.14 - 2026-07-23

- Restored the release quality gate after the 0.32.13 hardening work: fixed two Pint formatting failures, one unreachable PHPStan branch, and three stale Feature-test assumptions.
- The VDS CLI updater regression now uses a migrated test database, verifies the actual Symfony command signature for `--yes`, and keeps the behavior-level missing-package assertion.
- Windows release metadata tests now accept both LF and native CRLF line endings without weakening the SHA256 fingerprint check.
- Composer/npm dependencies, database migrations, application behavior, and deployment layouts were not changed.

## 0.32.13 - 2026-07-23

- Hardened shared-hosting packages: runtime uploads, public storage, and hot-reload markers are excluded; the public uploads skeleton blocks PHP-like execution; production Composer dependencies are rebuilt from an empty `vendor`; output paths are canonicalized before recursive cleanup.
- Hardened update backups with private `0700` directories and `0600` database/metadata files on Unix-like systems, including verification of the resulting mode.
- Hardened Web Installer password-bearing actions: HTTPS is required outside loopback development, resume locks are bound to the originating installation token, proxy HTTPS headers are trusted only from private/local proxies, and session rate limits protect database and install actions.
- Added the cumulative `php artisan kaevcms:update` command for VDS deployments so application code remains owned by the deployment user instead of becoming writable by PHP-FPM.
- Updated Ubuntu nginx guidance to execute only the front controllers and explicitly prevent PHP execution inside public uploads.
- Updated English and Russian installation/update documentation and fixed stale PHPUnit, browser, and formatting expectations reported by the production audit.
- Publisher signatures, atomic release directories, and expanded public-release CI remain deferred while KaevCMS is privately tested by one owner. Composer/npm dependencies and database migrations were not changed.

## 0.32.12 - 2026-07-23

- Added complete maintained English and Russian Ubuntu VDS deployment guides for Ubuntu Server 24.04 LTS, nginx, PHP 8.3-FPM, MySQL, HTTPS, permissions, scheduler, systemd queue worker, updates, verification, and troubleshooting.
- Documented Ubuntu 24.04 as the initial validated VDS baseline because its native PHP 8.3 matches the Composer platform; Ubuntu 26.04 with PHP 8.5 remains a separate compatibility target until the complete quality gate passes.
- Linked the VDS guide from the root README, bilingual documentation indexes, installation manuals, and `deployment/vds`.
- Added a release regression that verifies both VDS manuals, the secure `public/` Document Root, temporary installer permissions, post-install hardening, cron, systemd, HTTPS, and bilingual parity.
- Composer/npm dependencies and database migrations were not changed.

## 0.32.11 - 2026-07-23

- Rounded the Kaev Aurelia Account quick-access section so its background matches the other dashboard surfaces on desktop and mobile.
- Added PHP 5.5-compatible public entry guards that show a readable bilingual PHP 8.3 requirement before Laravel or the Web Installer is parsed.
- Expanded Web Installer hosting diagnostics with detected paths, HTTPS warnings, permission details, and a post-install security review for the private core, `.env`, debug mode, runtime directories, uploads, and installer lock.
- Changed the Windows shared-hosting builder to prepare a production-only Composer `vendor` by default and archive it with portable forward-slash ZIP paths; development dependencies require an explicit diagnostic switch.
- Rebuilt the documentation as maintained English and Russian manuals, preserved old mixed-language references under `docs/history`, and added practical Beget, Jino, generic Document Root, permissions, and build-key examples.
- Updated shared-hosting package instructions, Windows tooling documentation, VDS notes, Web Update documentation, and reward-queue guidance in both languages.
- Composer/npm dependencies and database migrations were not changed.

## 0.32.10 - 2026-07-23

- Исправлен Web Installer: если выбранная база уже содержит владельца KaevCMS, установка теперь останавливается с понятным сообщением вместо молчаливого использования прежнего пароля.
- Создание владельца выполняется только при пустой таблице администраторов и внутри транзакции. После записи установщик повторно читает аккаунт и проверяет введённый пароль через активный Laravel-хешер до создания `installed.lock`.
- Добавлены автономные и release-регрессии существующей установки и обязательной проверки сохранённого пароля. Composer/npm-зависимости и миграции базы не изменялись.

## 0.32.9 - 2026-07-23

- Исправлена подготовка окружения для `quality.ps1`: перед запуском Larastan создаются обязательные runtime-каталоги, включая `bootstrap/cache` и каталоги `storage`.
- Полный релиз теперь содержит служебные `.gitignore` в runtime-каталогах, поэтому после распаковки ZIP обязательные папки не теряются как пустые.
- Добавлены PowerShell- и release-регрессии, проверяющие восстановление runtime-структуры до загрузки Laravel. Composer/npm-зависимости и миграции базы не изменялись.

## 0.32.8 - 2026-07-23

- Исправлена ошибка shared-hosting ZIP-сборщика на Windows: длина префикса пути вычислялась конкатенацией с `DIRECTORY_SEPARATOR`, из-за чего `substr()` получал строку вроде `77\` вместо числового смещения.
- Вычисление относительного пути ZIP вынесено в отдельную функцию с нормализацией `\` в `/` до удаления префикса. Это одинаково обрабатывает Windows-пути, смешанные разделители и Linux-пути.
- Добавлены регрессионные проверки чистых и смешанных Windows-путей. Composer/npm-зависимости и миграции базы не изменялись.

## 0.32.7 - 2026-07-23

- Исправлена Windows-сборка shared-hosting ZIP: PowerShell больше не переупаковывает каталог через `ZipFile::CreateFromDirectory`, который записывал обратные слеши в имена записей архива.
- `build-shared-hosting-package.ps1` теперь использует переносимый ZIP PHP-сборщика, проверяет SHA256, запрещает `\` и небезопасные пути, а также контролирует верхний уровень `kaevcms-core/`, `public_html/` и инструкции.
- Добавлена регрессия, которая реально создаёт тестовый ZIP и проверяет POSIX-разделители путей; текстовые release-тесты защищают Windows-обёртку от возврата к повторной упаковке.
- Composer/npm-зависимости и миграции базы не изменялись.


## 0.32.6 - 2026-07-23

- Исправлены 16 ошибок PHPStan в Web Updater без ослабления статического анализа: удалены недостижимые проверки уже гарантированных типов и уточнено сужение типов резервных копий при восстановлении.
- Исправлено определение абсолютного Windows-пути SQLite в предварительной проверке обновления. Некорректный шаблон мог завершить PHPStan ошибкой `Unknown modifier ']'`.
- Проверки ZIP-метаданных переведены на фактический контракт `ZipArchive`: недоступная запись отклоняется явно, а обязательные поля `name` и `size` больше не обрабатываются как необязательные.
- Composer/npm-зависимости и миграции базы не изменялись.

## 0.32.5 - 2026-07-23

- Исправлена release-регрессия, которая в архиве 0.32.4 ожидала источник обновления 0.32.2 вместо фактической версии 0.32.3. Проверка теперь вычисляет предыдущую patch-версию из текущего `VERSION` и сопоставляет её с `apply`- и `update`-скриптами.
- Исправлен PowerShell-сценарий чужого pending-маркера: тест теперь действительно использует другую целевую версию и проверяет отказ, а не создаёт корректный маркер.
- История удалений Web Updater дополнена устаревающим `apply-0.32.4.ps1`. Поведение приложения, Composer/npm-зависимости и миграции базы не изменялись.

## 0.32.4 - 2026-07-23

- Исправлено последнее нарушение Laravel Pint в Web Installer: присваивание переменной по ссылке приведено к формату `$state = &$_SESSION[...]`, совместимому с правилами `binary_operator_spaces` и `unary_operator_spaces`.
- Поведение Web Installer, Web Updater, Composer/npm-зависимости и миграции базы не изменялись.

## 0.32.3 - 2026-07-23

- Устранены два оставшихся нарушения Laravel Pint: конфликт правил форматирования пустых классов исключений Web Installer и положение открывающей скобки многострочного helper-метода в `UpdatePackageInspectorTest`.
- Пустые классы исключений получили внутренние поясняющие комментарии без изменения их поведения. Логика Web Installer и Web Updater, Composer/npm-зависимости и миграции базы не изменялись.

## 0.32.2 - 2026-07-23

- Исправлены release- и updater-тесты, которые содержали жёстко заданные версии 0.32.0/0.32.1 и ломались после перехода на 0.32.1. Тесты staging-пакета теперь вычисляют следующую patch-версию от текущего `VERSION`.
- Удалён конфликтующий регистронезависимый JSON-ключ `files`; счётчик пакета обновления использует отдельный ключ `update files` с полным паритетом RU/EN.
- Проверки исключений Web Updater теперь сравнивают локализованное сообщение через каталог переводов, а не ожидают английский текст при русской локали.
- Приведены к стилю Laravel Pint файлы резервного копирования базы, Web Installer, shared-hosting builder и unit-тест инспектора пакета. Логика обновления, Composer/npm-зависимости и миграции базы не изменялись.

## 0.32.1 - 2026-07-23

- Web Updater получил сохраняемые этапы выполнения: подготовка резервных копий, замена файлов, миграции, завершение и успешное окончание. Страница прерванного обновления показывает текущий этап.
- Автоматический и ручной откат больше не восстанавливают базу CMS до фактического начала миграций. Для этапа замены файлов требуется только файловая резервная копия; для миграций обязательны обе копии.
- Восстановление проверяет наличие всех обязательных backup-манифестов до первого изменения и не начинает частичный откат, если одна из нужных копий отсутствует. Неизвестное состояние старого прерванного обновления обрабатывается консервативно.
- SHA256 загруженного ZIP сохраняется в истории и повторно сверяется непосредственно перед установкой.
- Сборщик кумулятивных пакетов умеет сравнивать целевой релиз с предыдущим через `--previous-root`, автоматически находить удалённые файлы и накапливать их по версиям в `deployment/updates/deletions.json`.
- Добавлены регрессии этапов, обязательных и повреждённых резервных копий, изменения ZIP после загрузки и полного файлового backup/apply/rollback в standard- и split-схемах. Composer/npm-зависимости не изменены; добавлена одна миграция hardening таблицы `system_updates`.

## 0.32.0 - 2026-07-23

- Добавлен ручной Web Updater в административной панели владельца: кнопка под текущей версией ведёт в отдельный раздел загрузки, проверки, установки и истории обновлений.
- Введён кумулятивный формат `kaevcms-update.json`: один полный пакет может обновлять любую установленную версию из заявленного диапазона непосредственно до целевого релиза; каждый payload-файл проверяется по размеру и SHA256.
- Пакеты используют логические пути `core/` и `public/`, поэтому один updater работает как со стандартным `Document Root → public`, так и с безопасной split-установкой shared-hosting.
- Перед установкой проверяются версия PHP, расширения, диапазон версий, ZIP envelope, traversal, символические ссылки, незаявленные и конфликтующие файлы, защищённые runtime-пути, права записи и свободное место.
- Установка выполняется под атомарной блокировкой и в maintenance mode. До замены создаются резервные копии базы CMS и затрагиваемых файлов; затем запускаются миграции, очистка кэшей, `queue:restart` и фиксация установленной версии.
- При ошибке выполняется автоматическая попытка восстановления базы, файлов и прежней версии. Перед запуском владелец получает аварийную ссылку обхода maintenance mode; для оборванного запроса добавлено ручное восстановление по сохранённым манифестам резервных копий и общей блокировке updater-процесса. История, журнал и расположение резервной копии сохраняются в новой таблице `system_updates` и закрытом `storage`.
- Добавлены PHP-сборщик cumulative ZIP, Windows-обёртка, документация формата, unit/feature/release-регрессии и подключение проверки сборщика к `quality.ps1`.
- Автоматическое скачивание из GitHub и цифровая подпись пакетов пока не включены: 0.32.0 принимает только вручную выбранный доверенный ZIP. Web Updater 1.0 не заменяет `vendor` и отклоняет пакет при изменённом `composer.lock`; такие релизы требуют полного развёртывания. Composer/npm-зависимости самой 0.32.0 не изменены; добавлена одна миграция базы KaevCMS.

## 0.31.12 - 2026-07-22

- Исправлены пять нарушений форматирования Laravel Pint в сборщике shared-hosting, его layout-регрессии, Web Installer и двух release-тестах. Рабочая логика не изменялась.
- Добавлена регрессия релизных метаданных для обновления 0.31.11 → 0.31.12. Composer/npm-зависимости и миграции базы KaevCMS не изменены.

## 0.31.11 - 2026-07-22

- Добавлен безопасный split-пакет для shared-хостингов, которые не позволяют направить домен на `public/`: закрытое ядро `kaevcms-core` размещается вне web-root, а публичная папка содержит только входные файлы и ресурсы.
- Добавлены PHP-сборщик и Windows-обёртка `build-shared-hosting-package.ps1`; имя публичной и закрытой папки можно задавать параметрами, готовый ZIP и SHA-256 создаются в `dist/`.
- Сборщик не переносит локальные `.env*`, SQLite-базу, журналы, сессии, кэш и install/update lock-файлы; writable-каталоги создаются заново с чистым содержимым.
- Laravel поддерживает генерируемое переопределение public path, поэтому загрузки, темы, ресурсы, Artisan и Web Installer используют реальную публичную папку split-развёртывания.
- Прямой запуск внутреннего `deployment/hosting/web-installer/installer.php` закрыт. Проверка требований блокирует установку по `/public/install/`, если весь проект оказался доступен из web-root.
- Добавлены отдельные регрессии shared-hosting layout и обновлена документация обоих безопасных режимов. Composer/npm-зависимости и миграции базы не изменены.

## 0.31.10 - 2026-07-22

- Исправлены две устаревшие PHPUnit-регрессии после упрощения кабинета: скрытие игрового аккаунта теперь проверяется в группированном режиме, а тест Kaev Aurelia Account ожидает фактическую сетку модального выбора `account-avatar-picker`. Рабочее поведение кабинета не менялось.
- Исправлен PowerShell-тест обновления: сценарий «чужого» pending-маркера теперь действительно использует другую целевую версию и не принимает корректный маркер за ошибку.
- `browser-quality.ps1` проверяет наличие `@playwright/test` через файловую систему и при свежем ZIP выдаёт понятную команду `browser-setup.ps1` без `NativeCommandError` и стека Node.js.
- `setup.ps1` после завершения отдельно напоминает об однократной установке браузерных зависимостей. Composer/npm-зависимости и миграции базы KaevCMS не изменены.

## 0.31.9 - 2026-07-22

- Исправлена генерация `.env` в Web Installer: значения всегда безопасно заключаются в кавычки и экранируют пробелы, `#`, кавычки, обратные слэши, `$` и управляющие символы; повторная попытка установки сохраняет уже созданный `APP_KEY`.
- Пароль MySQL больше не возвращается в HTML после проверки подключения. Он остаётся только в серверной сессии и может повторно использоваться при пустом поле; формы работают через POST/Redirect/GET.
- Проверка MySQL теперь создаёт, изменяет и удаляет случайную служебную таблицу, подтверждая права CREATE/INSERT/ALTER/UPDATE/DELETE/DROP, необходимые миграциям.
- Добавлены `HttpOnly`, `SameSite=Lax`, `Secure` для HTTPS, strict cookie sessions, CSP, запрет фреймов, `nosniff`, no-cache и другие изолированные заголовки самого установщика. На HTTP показывается явное предупреждение перед вводом паролей.
- Добавлена атомарная файловая блокировка через `flock`, исключающая параллельную установку из двух окон. Незавершённая установка безопасно продолжается после создания `.env`, миграций или владельца; существующий владелец не дублируется, а финальный lock можно восстановить.
- Внутренние PDO, SQL, файловые и Artisan-ошибки больше не выводятся в браузер: подробности с редактированным паролем пишутся в `storage/logs/installer.log`, пользователю показывается короткий код. Результаты всех критических Artisan-команд проверяются.
- Добавлен самостоятельный регрессионный тест `deployment/hosting/web-installer/tests/installer-regression.php`, включённый в Windows quality-gate. Composer/npm-зависимости и миграции базы KaevCMS не изменены.

## 0.31.8 - 2026-07-22

- Вся Windows-инфраструктура перенесена из корня в `deployment/windows`: установка, запуск, диагностика, quality-gate, Playwright, security audit, updater, apply-скрипт, support-функции и собственные PowerShell-регрессии. Скрипты сами определяют корень проекта и не требуют ручного копирования.
- Добавлена единая структура `deployment/hosting` и `deployment/vds`, чтобы Windows, обычный хостинг и будущий VDS развивались в одной сборке без трёх отдельных кодовых баз.
- Добавлен первый Web Installer по адресу `/install/`: приветствие, проверка PHP 8.3/расширений/прав, настройка сайта и MySQL, проверка подключения, создание владельца, миграции, генерация `.env` и `APP_KEY`, а также блокировка повторной установки.
- `public/index.php` перенаправляет новую установку без `.env` в мастер, а `public/.htaccess` добавляет стандартную Apache-маршрутизацию на `public/index.php`.
- Windows setup/update создают `storage/app/installed.lock`, поэтому web-установщик не открывает повторную установку существующего проекта.
- Добавлены регрессии поставки Web Installer и единого расположения PowerShell-файлов. Composer/npm-зависимости и миграции базы KaevCMS не изменены.

## 0.31.7 - 2026-07-22

- Личный кабинет разгружен и разделён на три понятные сущности: профиль пользователя KaevCMS, технические игровые аккаунты и игровые персонажи. Аватар профиля больше не используется на карточках и страницах игровых логинов; они получили нейтральную иконку.
- Выбор аватара перенесён в модальное окно поверх текущей страницы. Оно открывается по нажатию на аватар или из компактного меню профиля, использует прежний серверный каталог, CSRF, rate limit, аудит и безопасный внутренний адрес возврата.
- Добавлен отдельный раздел `/account/characters` и пункт «Персонажи» в боковой панели. Плоский режим «Все персонажи» стал новым стандартом, а группировка по серверам сохранена как пользовательский вариант.
- Главная `/account` больше не загружает вложенный каталог и карточки игровых аккаунтов: оставлены краткие показатели и быстрые переходы к персонажам, аккаунтам и веб-инвентарю. Верхнее меню профиля перестало дублировать игровые разделы боковой панели.
- В `user_character_preferences` добавлено служебное поле `schema_version`: существующие настройки один раз переводятся в плоский режим, после чего новый выбор игрока сохраняется без повторного сброса.
- Kaev Aurelia Account и L2 Obsidian Luxury обновлены до 1.3.0. Добавлены функциональные и браузерные регрессии маршрутов, модального окна, разделения аватаров, компактного обзора и обновления настроек персонажей.
- Composer/npm-зависимости не изменены. Добавлена одна миграция базы KaevCMS.

## 0.31.6 - 2026-07-22

- Добавлен полноценный выбор аватара профиля игрока из изображений, подготовленных владельцем сайта. Файлы WebP/PNG/JPEG автоматически обнаруживаются в `public/uploads/account-avatars`; загрузка собственных изображений игроком отсутствует.
- В таблицу `users` добавлено nullable-поле `avatar_filename`. В базе хранится только безопасное имя файла; удалённый или недоступный файл автоматически заменяется буквенной заглушкой без сломанной ссылки.
- Новый аватар используется в боковой панели, верхнем меню, карточках игровых аккаунтов и заголовке игрового аккаунта. Для заголовка добавлены нормальные внутренние отступы, поэтому аватар больше не прижат к краю карточки.
- Добавлены страница `/account/profile`, локализованные маршруты, аудит изменения, безопасная серверная проверка выбора, диагностика каталога и документация `docs/ACCOUNT_AVATARS.md`.
- Kaev Aurelia Account и L2 Obsidian Luxury обновлены до 1.2.0. Добавлены PHPUnit, unit- и Playwright-регрессии каталога, выбора, сброса и fallback-поведения.
- Composer/npm-зависимости не изменены. Добавлена одна миграция базы KaevCMS.

## 0.31.5 - 2026-07-22

- Исправлено нарушение Laravel Pint `single_blank_line_at_eof` в `modules/promo-codes/src/Models/PromoCodeReward.php`; рабочая логика модуля не изменена.
- Добавлена регрессия релизных метаданных для обновления 0.31.4 → 0.31.5. Composer/npm-зависимости и миграции не изменены.

## 0.31.4 - 2026-07-22

- Название административного раздела и заголовок страницы унифицированы как «Очередь наград».
- Журнал передач перестроен на стандартную таблицу «Журнала действий»: дата и время, пользователь и email, сервер, персонаж и аккаунт теперь отображаются отдельными строками; награды получили компактные карточки, иконки, локализованные названия и технический ID для администратора.
- Добавлен общий динамический каталог предметов `lang/{locale}/items.php` с общими названиями и необязательными переопределениями по ID GameServer. Встроен пример `57 => Адена / Adena`.
- Веб-инвентарь и история промокодов больше не показывают игроку `Предмет №ID`; неизвестный предмет получает нейтральное название без раскрытия ID. Административные разделы сохраняют ID как техническую подпись.
- Иконки предметов из `public/uploads/game-assets/items` подключены также к истории передач. Названия применяются к уже созданным наградам без миграции базы.
- Модуль `promo-codes` обновлён до 1.0.3, Kaev Aurelia Account — до 1.1.4, L2 Obsidian Luxury — до 1.1.2. Добавлены регрессии каталога, журнала очереди, веб-инвентаря и браузерного сценария.
- Composer/npm-зависимости и миграции базы KaevCMS не изменены.

## 0.31.3 - 2026-07-22

- Устранены четыре замечания Larastan/PHPStan без изменения рабочего поведения: обязательные `class_id`, `race` и `gender` читаются согласно контракту `GameAccountGateway`, а лишний `array_values()` удалён из уже индексированного списка рейтинга главной страницы. Тестовый `FakeGameAccountGateway` теперь также гарантирует обязательные поля внешности, даже когда конкретный сценарий задаёт только нужные значения.
- `browser-setup.ps1` теперь явно устанавливает dev-зависимости через `npm ci --include=dev`, поэтому Playwright не пропускается при системном `NODE_ENV=production`; установка Chromium использует локальный `npm exec`.
- `browser-quality.ps1` проверяет установленный пакет через Node resolution и поясняет, что отсутствие `node_modules` нормально для свежего ZIP и требует однократного запуска `browser-setup.ps1`.
- Актуализирована вводная часть дорожной карты. Composer/npm-зависимости и миграции базы KaevCMS не изменены.

## 0.31.2 - 2026-07-22

- Исправлены два нарушения форматирования Laravel Pint в импортах контроллера игровых аккаунтов и bootstrap-конфигурации; логика приложения не изменена.
- Браузерный тест раздела модулей больше не ожидает пустое состояние при установленном модуле промокодов и проверяет фактически обнаруженную карточку модуля.
- Проверка промокода без общего лимита привязана к созданной строке и учитывает полный текст метаданных «Общий лимит: без лимита», а не ищет отдельный текстовый узел.
- Релиз обновляется напрямую с принятой 0.30.0 и безопасно подхватывает pending-маркеры незавершённых кандидатов 0.31.0 и 0.31.1. Composer/npm-зависимости и миграции базы KaevCMS не изменены.

## 0.31.1 - 2026-07-22

- Исправлено чтение персонажей с неполным набором необязательных полей: отсутствие `created_at` больше не превращает весь игровой аккаунт в состояние «данные временно недоступны» и не скрывает рассчитанный аватар.
- Тесты постоянной Livewire-оболочки больше не зависят от имени vendor-файла `livewire.js` или `livewire.min.js`; проверяется фактический endpoint обновления Livewire.
- Исправлена регрессия PowerShell-проверки обновления: pending-маркер корректно означает, что установленной остаётся исходная версия до успешной фиксации релиза. Добавлена проверка безопасного продолжения после незавершённого кандидата 0.31.0.
- Исправлена изоляция теста Scheduler: создание второй операции больше не удаляет GameServer, на который уже ссылается первая передача наград.
- Релиз обновляется напрямую с принятой 0.30.0 и подхватывает сохранённый pending-маркер неуспешной 0.31.0. Composer/npm-зависимости и миграции базы KaevCMS не изменены.

## 0.31.0 - 2026-07-22

- Добавлен фундамент аватаров персонажей без поставки игровых изображений: `CharacterAppearanceResolver` преобразует `race`, `sex` и `class_id` в безопасные ключи расы, пола и общего архетипа `warrior / mage / default`.
- Базовые расы поддерживают варианты воин/маг, а расы с одним визуальным вариантом, включая гномов и камаэлей, намеренно используют `default`; неизвестные современные классы не получают случайную аватарку.
- `GameAssetUrlResolver` расширен безопасными вложенными ключами и fallback-цепочкой. Серверный набор имеет приоритет над общим, абсолютные пути и попытки выхода из каталога отклоняются.
- Аватары подключены к каталогу персонажей, странице игрового аккаунта, выбору персонажа в веб-инвентаре, публичной статистике и рейтингу на главной. При отсутствии файла сохраняется прежняя буквенная заглушка.
- Добавлены расы Ertheia и Sylph в русские и английские подписи, сохранены одинаковый порядок и полный паритет ключей языковых пакетов.
- Обновлена документация `docs/GAME_ASSETS.md` и добавлена практическая схема минимального набора `docs/CHARACTER_AVATARS.md`.
- Добавлены регрессии классификации, неизвестных значений, приоритета серверного набора, общего fallback, защиты путей и фактического вывода изображения в кабинете.
- Для сброса браузерного кэша обновлены версии встроенных тем. Composer/npm-зависимости и миграции базы KaevCMS не изменены.

## 0.30.0 - 2026-07-21

- Проведён полный технический аудит стабильной 0.29.3: безопасность, роли, внешние базы, модули, локализация, установка, обновление, публичный интерфейс и тестовая инфраструктура.
- Исправлена очистка изображений новостей: файл больше не удаляется, если он используется хотя бы в одном переводе `news_translations`, даже когда legacy-поле `news.body` его не содержит.
- Удалён устаревший `GameServerAdapter`, mock-рейтинг, отдельный `config/game.php` и неиспользуемое ENV-подключение `game`. Главная страница загружает реальный рейтинг через общий GameWorld-драйвер и показывает честное пустое состояние при отсутствии данных.
- Добавлено безопасное восстановление зависших передач наград: операции повторно сверяются по тому же `operation_uuid`; зависшие `pending` автоматически проверяются Scheduler, а неопределённые `review` повторяются только вручную, чтобы не создавать бесконечные запросы и журналирование.
- Подтверждённое отсутствие записи возвращает зарезервированные награды в веб-инвентарь; подтверждённая запись завершает передачу; конфликт содержимого UUID остаётся на ручной проверке и никогда не освобождает предметы автоматически.
- Добавлено отдельное право `rewards.manage`, кнопка «Проверить повторно», команда `kaevcms:rewards-reconcile` и аудит результатов восстановления.
- Публичный сайт и личный кабинет получили безопасные неблокирующие заголовки `nosniff`, `Referrer-Policy`, `Permissions-Policy` и `SAMEORIGIN`; строгий `DENY` и CSP админки сохранены.
- Чистая `.env.example` теперь использует production-безопасные значения `APP_ENV=production`, `APP_DEBUG=false` и `LOG_LEVEL=warning`. Устаревшие `GAME_ADAPTER` и `GAME_DB_*` удалены; `pdo_mysql` проверяется установщиком всегда для внешних игровых баз.
- Исправлены смешанные русско-английские формулировки в промокодах и очереди наград, переведены стандартные приветствие и подпись системных писем, добавлена автоматическая проверка совпадения ключей, плейсхолдеров и буквальных ключей из PHP/Blade для RU/EN.
- Полные сообщения исключений больше не записываются при ошибках блокировки мониторинга серверов.
- Добавлен отчёт `docs/AUDIT-0.30.0.md` и содержательные регрессии для переводных изображений, реального рейтинга, заголовков безопасности и восстановления неопределённых передач.
- Composer/npm-зависимости и миграции базы KaevCMS не изменены.

## 0.29.3 - 2026-07-21

- Исправлены два ложных падения Playwright: тест раздела модулей теперь раскрывает группу бокового меню перед переходом, а тест динамических наград использует стабильный `data-promo-reward-add` вместо точного доступного имени с декоративным символом `+`.
- Рабочий интерфейс, Composer/npm-зависимости и миграции базы KaevCMS не изменены.

## 0.29.2 - 2026-07-21

- Релиз заменяет непринятый кандидат 0.29.1 и обновляется напрямую с принятой версии 0.29.0; незавершённый pending-маркер 0.29.1 безопасно переводится на повторный запуск 0.29.2.
- Публичная навигация статистики теперь использует тот же кэшированный `sectionState`, что и сама страница: после первой ошибки внешней игровой БД header больше не выполняет дополнительные проверки и соблюдает короткий cooldown.
- Устранены оставшиеся замечания PHPStan в выборе `karma/reputation` и точной типизации строк `kaev_reward_queue` без ослабления правил анализа.
- Composer/npm-зависимости и миграции базы KaevCMS не изменены.

## 0.29.1 - 2026-07-21

- Исправлена публичная статистика: недоступная игровая база больше не отображается как отключённые администратором разделы; повторные проверки схемы используют короткий cooldown.
- Тесты справочника классов теперь явно проверяют английскую и русскую локализацию, не завися от языка тестового приложения.
- Устранены шесть замечаний PHPStan в определении Mobius-схемы, кэшировании профиля и записи `kaev_reward_queue` без ослабления правил анализа.
- Composer/npm-зависимости и миграции базы KaevCMS не изменены.

## 0.29.0 - 2026-07-21

- Проведён стабилизационный аудит после крупных изменений модулей, промокодов, веб-инвентаря, очереди наград и общего драйвера L2J Mobius. Новый крупный функциональный модуль в релиз не добавлялся.
- Kaev Aurelia Account обновлена до 1.1.2: блок последних активаций промокодов получил общую округлую поверхность, радиусы веб-инвентаря приведены к единым токенам, а маленькие изображения предметов больше не выбиваются квадратными углами.
- Исправлен декоративный слой боковой карточки формы: абсолютная внутренняя рамка теперь позиционируется относительно карточки и обрезается её скруглением, а не может выходить за пределы блока.
- Модульные ссылки в сохранённой боковой панели личного кабинета используют `wire:current`, поэтому активный пункт корректно меняется после Livewire-навигации и истории браузера.
- Из журналов Mobius-адаптера и мониторинга онлайна убран полный текст исключений внешней БД; сохраняются только безопасные диагностические поля без подробностей PDO/MySQL.
- Из чистой сборки удалён одноразовый `remove-legacy-bridge.sql`. Исторические записи CHANGELOG сохранены, а системные heartbeat Scheduler и database-очередей не затрагивались, поскольку не относятся к Reward Bridge.
- Добавлены функциональные и браузерные регрессии скруглений, декоративной рамки, активной модульной навигации, безопасного логирования и отсутствия legacy SQL-файла.
- Версия обновлена до 0.29.0. Composer/npm-зависимости и миграции базы KaevCMS не изменены.

## 0.28.0 - 2026-07-21

- Существующий GameServer-драйвер `l2j_mobius_ct0_interlude` расширен до единого драйвера L2J Mobius без изменения идентификатора уже настроенных серверов.
- Добавлены два внутренних профиля схемы: `mobius_legacy` использует `characters.karma`, а `mobius_modern` — `characters.reputation`; оба поля нормализуются внутри драйвера в единое значение `reputation`.
- Профиль определяется по фактическим колонкам таблицы `characters`. Выбранная хроника используется только как подсказка, когда в нестандартной базе одновременно существуют обе колонки; отсутствие обеих возвращает понятную ошибку несовместимой схемы.
- Поддержка `heroes` и `castle` стала динамической: отсутствие необязательной таблицы отключает только соответствующий раздел статистики и не ломает персонажей, рейтинги или подключение GameServer.
- `account_gsdata` и `account_premium` удалены из требований GameServer-драйвера, поскольку текущая CMS не выполняет к ним запросов.
- Справочник классов отделён от SQL-профиля. Известные старые классы сохраняют названия, а неизвестные ID безопасно отображаются как `Class #ID`, без подстановки неверного класса Interlude.
- Проверка внешней базы получила требование `any_columns`, поэтому `characters` считается совместимой при наличии хотя бы одной из колонок `karma` или `reputation`.
- Добавлены регрессии legacy/modern-схем, нормализации репутации, отсутствующих героев и замков, несовместимой таблицы `characters`, безопасного имени неизвестного класса и сохранённого старого идентификатора драйвера.
- Версия обновлена до 0.28.0. Composer/npm-зависимости и миграции базы KaevCMS не изменены.

## 0.27.0 - 2026-07-21

- Полностью удалён обязательный Kaev Reward Bridge: Java-файлы, патч `CharacterSelect`, heartbeat, проверка версии протокола и фоновые reward-job больше не входят в сборку.
- Добавлена единая нейтральная таблица `kaev_reward_queue` для всех GameServer-сборок. KaevCMS записывает сервер, CMS-пользователя, аккаунт, персонажа, `item_id`, количество и UUID операции, но не изменяет таблицу `items`.
- Передача наград больше не относится к `GameWorldDriver`: общий `DatabaseGameRewardQueueGateway` работает одинаково с любой подключённой игровой базой, где установлена минимальная схема очереди.
- Онлайн-персонаж больше не блокирует передачу: CMS только сохраняет задание, а реальный обработчик и безопасный момент выдачи определяет владелец GameServer.
- Запись выполняется синхронно и идемпотентно. Подтверждённая ошибка возвращает награды в веб-инвентарь; неопределённый результат оставляет их зарезервированными для ручной проверки.
- Добавлены `install.sql`, запрос просмотра очереди и шаблоны ручной обработки. Универсальный прямой `INSERT INTO items` намеренно не поставляется, потому что схема инвентаря и генерация object ID зависят от конкретной сборки.
- Добавлен необязательный `remove-legacy-bridge.sql`, который удаляет только три старые Bridge-таблицы и не затрагивает `kaev_reward_queue`, персонажей или `items`.
- Журнал и личный кабинет теперь честно показывают «передано в очередь GameServer», не заявляя о фактической выдаче предмета.
- Добавлены функциональные тесты минимальной схемы, идемпотентности, конфликта UUID, онлайн-персонажа, подтверждённой ошибки и неопределённого результата.
- Версия обновлена до 0.27.0. Composer/npm-зависимости не изменены. Добавлена одна миграция ядра для перехода со старых локальных статусов и удаления устаревших reward-job при обновлении.

## 0.26.2 - 2026-07-21

- Релиз заменяет непринятый кандидат 0.26.1 и обновляется напрямую с принятой версии 0.26.0.
- Исправлена ложная регрессия удаления промокода: тест теперь проверяет отсутствие ссылки удалённого промокода в рабочем списке и не принимает flash-сообщение об успешном удалении за оставшуюся запись.
- Логика удаления, журнал активаций, начисленные награды, зависимости и миграции не изменены.

## 0.26.1 - 2026-07-21

- Исправлена путаница с повторными архивами кандидата 0.26.0: доработки промокодов оформлены отдельным релизом 0.26.1 с собственным apply-скриптом и SHA-256.
- Добавлено безопасное удаление промокодов: неиспользованный код удаляется полностью, а код с активациями скрывается мягко без потери журнала и уже начисленных наград.
- Редактор наград стал компактным: `ID предмета` и `Количество` расположены в одной строке, по умолчанию отображается одна награда, дополнительные строки добавляются кнопкой «Добавить предмет».
- Исправлен текст выбора сервера на «Выберите сервер». Административные модули собраны в отдельную группу меню, где первым идёт системный пункт «Модули», а ниже — ссылки установленных модулей.
- Модуль `promo-codes` обновлён до 1.0.2, чтобы KaevCMS гарантированно запросила подтверждение новой версии кода и применила миграцию мягкого удаления.
- Добавлены регрессионные и браузерные проверки удаления, сохранения истории, динамических строк наград и порядка модульной навигации.
- Composer/npm-зависимости и миграции ядра KaevCMS не изменены. Добавлена только миграция модуля `promo-codes`.

## 0.26.0 - 2026-07-21

- Доработан кандидат релиза: промокоды можно безопасно удалять из рабочего списка; неиспользованный код удаляется полностью, а код с историей получает мягкое удаление, поэтому журнал активаций, уже начисленные награды и аудит не теряются.
- Модуль `promo-codes` обновлён до 1.0.1 и получил четвёртую собственную миграцию с полем `deleted_at`; удалённый неиспользованный промокод больше не блокирует удаление GameServer, но история активаций продолжает блокировать его.
- Форма наград стала компактной: `ID предмета` и `Количество` находятся в одной строке, по умолчанию показывается одна награда, дополнительные строки добавляются и удаляются динамически до серверного лимита 100.
- Текст выбора GameServer заменён на единое локализованное «Выберите сервер», а административные модули собраны в отдельную группу бокового меню: сначала системный раздел «Модули», затем ссылки включённых модулей.
- Добавлены функциональные и браузерные регрессии мягкого удаления, сохранения истории, динамических строк наград и нового порядка административной навигации.
- Исправлен кандидат релиза: страница промокодов в личном кабинете теперь передаёт авторизованного пользователя в layout активной темы и больше не падает при открытии.
- Добавлена функциональная регрессия открытия страницы промокодов обычным пользователем; форматирование `AdminPromoCodeController` приведено к правилам Laravel Pint.
- Исправлен кандидат релиза: форматирование контроллера и функционального теста промокодов приведено к правилам Laravel Pint (`fully_qualified_strict_types`, `unary_operator_spaces`, `ordered_imports`).
- Исправлен кандидат релиза: проверка read-only режима промокодов теперь ищет кнопку сохранения только внутри формы модуля и не принимает системные кнопки смены языка или выхода в общем административном шаблоне за возможность редактирования.
- Исправлен кандидат релиза: динамические маршруты модулей теперь повторно индексируются после загрузки route-файлов, поэтому fluent-имена доступны через `Route::has()`, `route()` и редиректы даже при поздней регистрации и включённом core route cache.
- Исправлен кандидат релиза: установка модуля теперь сбрасывает устаревший кэш состояния перед проверкой служебной таблицы миграций, поэтому PHPUnit и BrowserTestSeeder корректно включают `promo-codes` после подготовки тестовой базы.
- Ссылка модуля в сохраняемом Livewire-меню переведена на `wire:current`, чтобы активный пункт обновлялся после навигации без серверного класса, зафиксированного при первом рендере боковой панели.
- Добавлен встроенный модуль `promo-codes` с собственными неизменяемыми миграциями, административным разделом, страницей активации в личном кабинете и динамическими RU/EN-переводами.
- Промокод содержит код длиной 4–64 символа, GameServer, календарный срок действия, общий лимит (`0` — без лимита), лимит на один CMS-аккаунт, состояние и до 100 уникальных наград `item_id + amount`.
- Активация выполняется одной блокируемой транзакцией: проверяются даты и лимиты, создаётся журнал, а награды начисляются через существующий `RewardInventoryService`; UUID запроса и неизменяемый `grant_key` защищают от двойного начисления.
- После первой успешной активации GameServer промокода изменить нельзя; редактирование будущих наград не переписывает журнал, потому что он показывает точный снимок фактического начисления веб-инвентаря.
- Владелец может включать, отключать и безопасно удалять промокоды без потери истории. Администратор получает режим чтения, редактор доступа не имеет; изменения состояния, настроек и удаления записываются в аудит.
- Модуль блокирует удаление связанного GameServer, а внешние ключи с `restrictOnDelete` сохраняют целостность даже при отключённом runtime модуля.
- В ядро добавлены безопасные реестры навигации модулей и зависимостей GameServer, чтобы включённые модули могли добавлять свои разделы без жёсткой связи с ядром.
- Добавлен общий сохраняемый каталог `public/uploads/game-assets` и `GameAssetUrlResolver`: сначала используется иконка конкретного GameServer, затем общий файл; поддерживаются `webp`, `png`, `jpg` и `jpeg`.
- Веб-инвентарь и модуль промокодов используют общий резолвер иконок. Подготовлены также безопасные ключи аватаров персонажей для будущих разделов.
- `setup.ps1`, `update.ps1` и системная диагностика создают и проверяют каталог игровых изображений; файлы внутри `public/uploads` не удаляются обновлениями и должны входить в резервную копию.
- Добавлены функциональные регрессии для миграций модуля, прав, валидации, лимитов, транзакционного начисления, идемпотентности, сохранения истории, запрета смены сервера, защиты удаления GameServer и разрешения иконок.
- BrowserTestSeeder и Playwright дополнены реальным созданием промокода, календарными полями, активацией игроком и проверкой появления наград в веб-инвентаре.
- Версия обновлена до 0.26.0; добавлен `apply-0.26.0.ps1` с проверкой исходной версии 0.25.2. Composer/npm-зависимости и миграции ядра KaevCMS не изменены; четыре миграции принадлежат модулю и применяются только при его установке владельцем.

## 0.25.2 - 2026-07-21

- Для Mobius CT0 Interlude добавлена первая безопасная реальная выдача наград офлайн-персонажу через Kaev Reward Bridge, работающий внутри GameServer.
- Прямая запись CMS в `items` намеренно не используется: работающий Mobius распределяет `object_id` через собственный `IdManager` в памяти, поэтому SQL-вычисление нового ID не может гарантировать отсутствие коллизии.
- Bridge устанавливает отдельные таблицы очереди в игровой базе, публикует heartbeat и создаёт предметы штатным `IdManager`; поддерживаются только простые постоянные предметы `item_id + amount`, без заточки, атрибутов, аугментации и временных предметов.
- Перед выдачей повторно проверяются GameServer, владелец персонажа, состояние `online` в базе и наличие персонажа в памяти GameServer; одна операция ограничена 1 000 создаваемых объектов.
- Операции защищены UUID и SHA-256 содержимого: повтор с тем же UUID идемпотентен, а попытка повторно использовать UUID с другим набором наград отклоняется.
- Запись предметов и подтверждение `delivered` выполняются одной транзакцией. Подтверждённая ошибка переводит операцию в `failed`, а потерянный результат коммита или зависший `processing` переводится в неопределённое состояние без автоматического возврата наград.
- `ProcessRewardDelivery` и повторная проверка результата выполняются в постоянной database-очереди `rewards`; новый `ConfirmRewardDelivery` опрашивает bridge без повторной постановки предметов на выдачу.
- Capability-флаги Mobius-драйвера включаются только при наличии совместимой схемы bridge и свежего heartbeat; интерфейс различает отсутствие, несовместимую версию и остановленный bridge.
- Добавлены регрессии установки и heartbeat bridge, идемпотентности, конфликта содержимого, повторной проверки владельца и офлайн-состояния, подтверждения успеха, подтверждённой ошибки, неопределённого результата и постоянной очереди.
- Исправлено форматирование многострочной сигнатуры в Mobius Interlude-драйвере, из-за которого `pint --test` останавливал `quality.ps1` при проверке кандидата релиза.
- Composer/npm-зависимости и миграции базы KaevCMS не менялись. Добавлен отдельный `install.sql` для игровой базы Mobius и Java-скрипт bridge.
- Версия обновлена до 0.25.2; добавлен `apply-0.25.2.ps1` с проверкой исходной версии 0.25.1.

## 0.25.1 - 2026-07-21

- Kaev Aurelia Account обновлена до 1.1.1: веб-инвентарь получил общий контейнер `account-surface` с теми же плавными скруглениями, фоном, рамкой и тенью, что и остальные разделы кабинета.
- Добавлен переиспользуемый CSS-класс `account-surface`, чтобы новые верхнеуровневые разделы кабинета сразу оформлялись как закруглённые поверхности и не повторяли ошибку веб-инвентаря.
- В административном меню группа «Оформление» переименована в «Темы», а вложенные пункты упрощены до «Сайт» и «Кабинет» без изменения маршрутов, прав доступа и активных тем.
- Физические каталоги `themes` и `account-themes` намеренно не объединялись: это не даёт функциональной пользы, но потребовало бы рискованной миграции уже установленных и пользовательских тем. Пользовательский интерфейс теперь объединяет их логически без изменения совместимости пакетов.
- Добавлены функциональные и браузерные регрессии версии темы, общего закруглённого контейнера веб-инвентаря и новой структуры навигационных подписей.
- Composer/npm-зависимости и схема базы данных не менялись.
- Версия обновлена до 0.25.1; добавлен `apply-0.25.1.ps1` с проверкой исходной версии 0.25.0.

## 0.25.0 - 2026-07-21

- Добавлено ядро веб-инвентаря: атомарные пакеты наград, позиции склада, операции передачи и неизменяемые снимки передаваемых предметов хранятся в базе KaevCMS.
- Каждая награда жёстко привязана к одному GameServer; интерфейс скрывает выбор сервера при единственном складе и показывает отдельные вкладки при нескольких серверах.
- Добавлен идемпотентный API `RewardInventoryService`: повторный `grant_key` возвращает исходный пакет и не начисляет предметы второй раз.
- Игрок может выбрать до 50 доступных наград, затем выбрать только принадлежащего ему персонажа текущего сервера и поставить передачу в очередь.
- Транзакционная блокировка пользователя и позиций склада защищает от двойного клика и конкурентных запросов; повторный `request_token` возвращает ту же операцию.
- Добавлен безопасный жизненный цикл передачи `pending → processing → delivered/failed/review`: подтверждённая ошибка без выдачи возвращает награды на склад, а неизвестный результат оставляет их зарезервированными для проверки и исключает потенциальную повторную выдачу.
- `GameWorldDriver` получил нормализованный контракт возможностей и выдачи простых предметов. Встроенный Mobius Interlude-драйвер пока честно сообщает, что прямая выдача не реализована, поэтому реальные таблицы `items` не изменяются.
- В обе встроенные темы кабинета добавлены серверный веб-инвентарь, список доступных наград, выбор персонажа и история передач; Kaev Aurelia Account и Luxury обновлены до 1.1.0.
- В административной панели появился read-only журнал передач наград для владельца и администратора; редактор доступа не получает.
- Удаление GameServer с наградами или историей выдачи блокируется понятной ошибкой, чтобы внешние ключи не приводили к потере веб-инвентаря или необработанному сбою панели.
- Добавлена документация `docs/WEB_INVENTORY.md` и регрессии разделения серверов, идемпотентного начисления, владения предметами и персонажем, успешной, подтверждённо неудачной и неопределённой обработки, а также ролевого доступа.
- Версия обновлена до 0.25.0; добавлен `apply-0.25.0.ps1` с проверкой исходной версии 0.24.1 и миграцией таблиц веб-инвентаря.

## 0.24.1 - 2026-07-20

- Добавлен жизненный цикл базы данных модулей: манифест схемы 1 поддерживает каталог `migrations`, а файлы выполняются до подтверждения версии и загрузки PHP-кода модуля.
- Выполненные миграции учитываются отдельно для каждого модуля в `cms_module_migrations` вместе с SHA-256; повторное включение не запускает их второй раз.
- Добавлена защита истории схемы: изменение, переименование, удаление или скрытие уже выполненной миграции блокирует модуль до восстановления исходных файлов или выпуска новой миграции.
- Атомарная блокировка на модуль запрещает параллельный запуск двух установок или обновлений базы из разных административных запросов.
- При ошибке KaevCMS пытается откатить только миграции текущей попытки в обратном порядке, сохраняет безопасный класс исключения и не включает новый код; предыдущие успешные пакеты не затрагиваются.
- Отключение модуля больше явно гарантирует сохранение его таблиц и данных; автоматический destructive uninstall по-прежнему отсутствует.
- Runtime и маршруты fail-closed: включённый модуль не загружается, если появились новые миграции, произошла ошибка схемы или нарушена неизменность выполненных файлов.
- Административный раздел показывает готовность к установке, количество выполненных и ожидающих миграций, необходимость обновления базы, ошибку и отдельные действия установки, обновления и повторной попытки.
- Успешная установка, обновление базы и ошибка миграции получают отдельные события аудита; полные сообщения исключений и потенциальные секреты в состояние модуля не записываются.
- Добавлены функциональные регрессии первой установки, повторного включения, сохранения данных после отключения, обновления версии, блокировки runtime, checksum-контроля, отката ошибки, восстановления и конкурентного lock.
- Обновлены `docs/MODULES.md`, схема `module.json`, пример пакета и браузерная проверка административного раздела.
- Версия обновлена до 0.24.1; добавлен `apply-0.24.1.ps1` с проверкой исходной версии 0.24.0 и миграцией служебного учёта модулей.

## 0.24.0 - 2026-07-20

- Добавлено первое стабильное ядро модулей: каталог `modules`, строгий `module.json` схемы 1, проверка обязательных полей, семантической версии и совместимости с KaevCMS.
- Валидатор запрещает неизвестные поля, зарезервированные идентификаторы, небезопасные символические ссылки, абсолютные пути, обратные слеши и выход за пределы каталога модуля.
- Добавлена таблица `cms_modules` для состояния, подтверждённой версии, времени включения/отключения и безопасной диагностики ошибки загрузки.
- В административной панели появился рабочий раздел «Модули»: владелец включает, подтверждает обновлённую версию и отключает расширения; администратор получает режим чтения, редактор не имеет доступа.
- Включённые модули могут регистрировать PSR-4 namespace, bootstrap-сервисы, представления, переводы и маршруты под изолированными публичным и административным префиксами.
- Все маршруты модуля защищены runtime-проверкой состояния и намеренно не включаются в основной Laravel route cache: они регистрируются после кешированных маршрутов ядра, поэтому отключённый, повреждённый, отсутствующий или ожидающий подтверждения модуль не оставляет исполняемых устаревших маршрутов.
- Ошибка bootstrap или runtime одного модуля больше не должна останавливать CMS: сохраняется только безопасный код этапа и класса исключения, а загрузка автоматически повторяется после короткой защитной задержки без спама логов на каждом запросе.
- Замена файлов включённого модуля новой версией требует явного подтверждения владельца; неподтверждённый код не загружается автоматически.
- Включение, подтверждение версии, отключение и неудачные операции записываются в аудит; изменение состояния применяется со следующего запроса без сброса основного route cache сайта.
- Добавлены документация `docs/MODULES.md`, машинно-читаемая схема `resources/schemas/module.schema.json`, функциональные регрессии жизненного цикла и браузерная проверка административного раздела.
- В Kaev Aurelia Account исправлен fallback-аватар персонажа: буква имени строго центрируется и не выходит за границы круга; будущие изображения по расе и полу смогут заменить этот fallback без изменения компоновки карточки.
- Updater проверяет SHA256 `composer.lock` и пропускает `composer install`, когда набор PHP-зависимостей не изменился и `vendor` уже установлен; оптимизированный autoload всё равно перестраивается для новых классов ядра.
- Версия обновлена до 0.24.0; добавлен `apply-0.24.0.ps1` с проверкой исходной версии 0.23.12 и миграцией состояния модулей.

## 0.23.12 - 2026-07-20

- В стандартную поставку добавлена публичная тема **Kaev Aurelia 1.0.7** с полным набором страниц авторизации, новостей, контента, статистики, адаптивной Livewire-навигацией и собственными ресурсами.
- Добавлен независимый шаблон личного кабинета **Kaev Aurelia Account 1.0.4** с согласованным светлым оформлением, постоянной Livewire-оболочкой, каталогом персонажей и страницами игровых аккаунтов.
- Темы обнаруживаются штатными `ThemeManager` и `AccountThemeManager`, активируются раздельно и не изменяют бизнес-логику, маршруты или игровые драйверы.
- Обновление не переключает существующие установки автоматически; `default` и `luxury` сохранены как безопасные fallback-темы.
- Добавлены регрессии валидности манифестов, полного состава представлений и ресурсов, независимой активации, рендеринга публичного сайта и кабинета, а также наличия всех используемых ключей в базовых языковых пакетах.
- Документация тем дополнена перечнем встроенных пакетов и безопасным поведением при обновлении.
- Версия обновлена до 0.23.12; добавлен `apply-0.23.12.ps1` с проверкой исходной версии 0.23.11.

## 0.23.11 - 2026-07-20

- Исправлено наследование `COMPOSER_DISABLE_NETWORK=1` в текущем PowerShell-сеансе: `quality.ps1` восстанавливает исходное окружение, а ручной `security-audit.ps1` временно разрешает Composer сетевой доступ и затем возвращает прежнее значение.
- `quality.ps1` и повторные browser smoke-тесты больше не обращаются в интернет: Composer принудительно работает offline, а установка npm/Chromium вынесена в `browser-setup.ps1`.
- Актуальная проверка Composer/npm advisories вынесена в отдельный строгий `security-audit.ps1`, который запускается явно при доступном интернете.
- Browser runner выбирает свободный локальный порт вместо постоянного `8765`; только Windows-ошибка `ERR_NO_BUFFER_SPACE` получает ограниченный повтор навигации, не скрывая ошибки CMS.
- Отдельный `security-audit.ps1` различает временную недоступность Packagist, найденные advisories и несетевые ошибки; при любом незавершённом аудите команда завершается ошибкой, не выдавая ложный успешный результат.
- Успешный `composer audit` больше не превращается в `NativeCommandError`, если `composer.bat` пишет информационное сообщение в stderr: результат определяется по коду завершения процесса, а вывод безопасно нормализуется.
- Форма LoginServer приведена к той же вкладочной компоновке, что и GameServer: основные параметры и подключение к БД остаются во вкладке «Основное», а параметры проверки службы вынесены во вкладку «Сетевые настройки».
- После проверки подключения результат прокручивается внутри drawer, а нижняя панель с кнопками «Проверить подключение» и «Сохранить изменения» остаётся закреплённой и доступной.
- При ошибке адреса или порта службы Livewire автоматически открывает вкладку сетевых настроек, чтобы поле с ошибкой не было скрыто.
- Добавлены функциональные и браузерная регрессии для вкладок LoginServer, автоматического перехода к ошибочному полю и положения нижней панели после проверки соединения.
- Исправлены регрессии проверок самого релиза: тест Composer audit учитывает запуск через найденный executable, а браузерный сценарий LoginServer использует валидный placeholder-драйвер и гарантированно закрытый порт вместо зависимости от локальной MySQL.
- Документация очередей уточняет эксплуатацию: сама публикация CMS не запускает постоянный worker; на Linux/VDS используется Supervisor либо системный cron с `php artisan schedule:run` каждую минуту.
- Версия обновлена до 0.23.11; добавлен `apply-0.23.11.ps1` с проверкой исходной версии 0.23.10.

## 0.23.10 - 2026-07-20

- Усилено ограничение публичной авторизации: вход, регистрация и восстановление пароля учитывают одновременно IP-адрес и нормализованный логин или email.
- Добавлена проверка всех значений, зашифрованных через `APP_KEY`: SMTP, пароли LoginServer/GameServer, секреты 2FA и резервные коды администраторов.
- Системная информация и команда `kaevcms:encryption-health` показывают только безопасные счётчики и категории, не раскрывая сами секреты.
- `doctor.ps1` завершает проверку ошибкой, если сохранённые значения больше нельзя расшифровать текущим `APP_KEY`.
- `quality.ps1` и GitHub Actions запускают `composer audit --locked`; `browser-quality.ps1` и CI проверяют npm advisories уровня high и critical.
- Удалены устаревшие административные адреса `/admin/dashboard`, `/settings/system/monitoring` и `/settings/system/admin-path`; актуальные маршруты остаются единственным интерфейсом.
- Добавлены функциональные и браузерные регрессии для rate limit, APP_KEY-диагностики, dependency audit и отсутствия legacy-маршрутов.
- Исправлена изоляция теста недоступной базы шифрования: исходное SQLite-подключение восстанавливается до завершения теста; Playwright-проверки используют однозначные локаторы.
- Версия обновлена до 0.23.10; добавлен `apply-0.23.10.ps1` с проверкой исходной версии 0.23.9.

## 0.23.9 - 2026-07-20

- Расширена диагностика database-очереди: фиксируются начало, успешное завершение и ошибка задания, последняя успешная обработка и состояние каждой очереди.
- Scheduler больше не ограничен почтовыми очередями: команда `kaevcms:queue-drain` автоматически обрабатывает все очереди, реально присутствующие в таблице `jobs`.
- Добавлена страница управления очередями с безопасной сводкой, повторным запуском и удалением неудачных заданий, ручной очисткой и командой перезапуска worker.
- Payload заданий и полный текст исключений не выводятся в административном интерфейсе.
- Добавлена ежедневная очистка завершённых записей доставки почты, старых `failed_jobs` и неактивных heartbeat очередей; ожидающие письма не удаляются.
- После изменения SMTP CMS предупреждает о необходимости перезапустить постоянный worker.
- Updater после миграций автоматически отправляет `queue:restart`, чтобы постоянные worker не продолжали выполнять старый код.
- Добавлены функциональные и браузерные регрессии для динамического обхода очередей, жизненного цикла worker, хранения служебных данных, прав доступа и безопасного интерфейса.
- Версия обновлена до 0.23.9; добавлен `apply-0.23.9.ps1` с проверкой исходной версии 0.23.8.

## 0.23.8 - 2026-07-20

### Безопасное обновление

- добавлен постоянный маркер установленной версии в `storage/app/kaevcms/installed-version.json` с резервной записью в `cms_settings`;
- новая команда `kaevcms:release-version` читает и записывает только успешно установленную версию KaevCMS;
- патч проверяет фактическую исходную версию до миграций, тестов и удаления устаревших файлов;
- для первого перехода с 0.23.7 используется контрольная сумма официального `apply-0.23.7.ps1`, дальнейшие обновления используют постоянный маркер;
- PHP-файлы старого Laravel bootstrap cache удаляются до Composer package discovery;
- после очистки bootstrap cache CMS входит в maintenance mode, зависимости устанавливаются с `--no-scripts`, затем отдельно перестраивается autoload;
- состояние maintenance mode определяется через Laravel для file/cache-драйвера; режим снимается в `finally` только если его включил updater;
- устаревшие apply-скрипты и legacy-файлы личного кабинета до тестов перемещаются из активного дерева в резервную папку, а окончательно удаляются только после успешного обновления;
- незавершённое обновление сохраняет проверенный pending-маркер и резерв, поэтому тот же apply-скрипт можно безопасно запустить повторно;
- этапы обновления записываются в отдельный журнал в `storage/logs`;
- `doctor.ps1` проверяет совпадение постоянной установленной версии с текущим релизом.

### Безопасность

- `update.ps1` больше не изменяет пользовательский `.env`, не переключает `QUEUE_CONNECTION` и не добавляет устаревшее имя session cookie;
- задания отправки пользовательской почты используют шифрование queue payload средствами Laravel;
- reset-token, адрес получателя и сериализованное уведомление больше не хранятся открытым текстом в `jobs` и `failed_jobs`.

### Тесты

- добавлены функциональные проверки записи версии одновременно в файл и базу данных;
- добавлена fail-closed регрессия расхождения маркера и значения в базе;
- добавлены проверки отказа записывать версию, не совпадающую с извлечённым релизом;
- добавлен тест отсутствия reset-token и email пользователя в payload основной и неудачной очереди;
- добавлен самостоятельный PowerShell-набор проверки маркера, контрольной суммы предыдущего apply-скрипта, порядка очистки кэша и отсутствия изменений `.env`;
- PHPUnit использует отдельный in-memory maintenance store и больше не получает `503` из-за реального режима обслуживания обновляемого сайта;
- добавлены регрессии переноса старого apply-скрипта и legacy-папок `resources/views/account`, `resources/views/livewire/account`, `public/assets/account` в резерв до запуска тестов.

## 0.23.7 - 2026-07-20

### Диагностика Scheduler и очередей

- добавлена таблица `system_heartbeats` для безопасной фиксации последнего успешного запуска Laravel Scheduler и реальной активности обработчика очереди;
- Scheduler каждую минуту выполняет лёгкую команду `kaevcms:scheduler-heartbeat`, а очередь записывает heartbeat перед обработкой задания;
- диагностика показывает количество ожидающих заданий, `failed_jobs`, самое старое ожидающее задание и последнюю активность; почтовые показатели остаются в отдельной карточке «Доставка почты»;
- зависшая более двух минут очередь в режиме `database` получает системное предупреждение без дублирования почтовой карточки, а отсутствие Scheduler более трёх минут отображается как проблема;
- синхронный и обычный фоновый режимы почты не получают ложное требование постоянного queue worker;
- на главную административной панели добавлен блок «Системные процессы» для владельца и администратора; редактор не получает доступ к технической диагностике;
- раздел «Системная информация» и безопасный отчёт поддержки теперь используют те же реальные показатели Scheduler и очередей;
- `doctor.ps1` проверяет, что heartbeat Scheduler и обработка почтовой очереди зарегистрированы в Laravel schedule.

### Тесты

- добавлены проверки регистрации heartbeat каждую минуту и фактической записи отметки командой;
- добавлена регрессия фиксации активности при реальной обработке queue job;
- добавлены проверки зависших заданий, `failed_jobs`, отсутствия дублирования почтовой карточки и ложного требования worker для `sync`;
- добавлены проверки отображения системного блока администратору и его скрытия от редактора;
- добавлен браузерный сценарий Playwright, проверяющий видимость основных показателей нового блока дашборда;
- добавлена проверка диагностических задач в `doctor.ps1`.

## 0.23.6 - 2026-07-20

### Исправлено

- устранена критическая ошибка обновления `Target class [config] does not exist`, возникавшая во время `composer install`, Artisan и загрузки Larastan;
- настройка trusted proxies больше не читает `config()` на раннем этапе `withMiddleware`, когда контейнер конфигурации Laravel ещё не создан;
- добавлен собственный middleware trusted proxies, который получает безопасно разобранную настройку после полной загрузки приложения;
- поддержка `config:cache` сохранена: настройка продолжает читаться через конфигурацию Laravel, а не напрямую из `.env` во время ранней сборки приложения.

### Тесты

- добавлена регрессия раннего разрешения Console Kernel до запуска bootstrapper конфигурации — именно этот сценарий ломал `composer package:discover` и Larastan;
- добавлен функциональный тест, подтверждающий принятие `X-Forwarded-For` и HTTPS только от явно настроенного доверенного proxy;
- существующая проверка игнорирования поддельных forwarded-заголовков без доверенных proxy сохранена.

## 0.23.5 - 2026-07-20

### Инфраструктурная надёжность

- добавлена безопасная настройка `TRUSTED_PROXIES` для Cloudflare, балансировщиков и reverse proxy;
- пересылаемые IP, host, port и HTTPS-протокол принимаются только от явно указанных IP-адресов и CIDR-диапазонов;
- пустая настройка оставляет прямые подключения без изменений, неверные элементы игнорируются с предупреждением, а небезопасное доверие `*` отдельно отмечается в диагностике;
- SQLite получила минимальное ожидание блокировки `DB_BUSY_TIMEOUT=5000`, чтобы кратковременная занятость тестовой базы не сразу завершалась ошибкой `database is locked`;
- WAL и `synchronous=NORMAL` не включаются автоматически: SQLite остаётся вариантом для локальной разработки и тестов, а для публичной установки рекомендуется MySQL или MariaDB;
- системная информация показывает фактический драйвер и доступность базы, а для SQLite — реальный `busy_timeout`, режим журнала и синхронизации записи;
- production-окружение на SQLite получает понятное предупреждение, безопасный отчёт поддержки показывает только состояние proxy без раскрытия их адресов;
- `doctor.ps1` проверяет trusted proxies, значение SQLite lock wait и предупреждает об SQLite в production.

### Тесты

- добавлены модульные проверки разбора IP/CIDR, отбрасывания неверных proxy и безопасной обработки `*`;
- добавлена регрессия, подтверждающая, что без настройки CMS игнорирует поддельные `X-Forwarded-*` заголовки;
- системные тесты проверяют диагностику SQLite, скрытие адресов proxy в отчёте и предупреждение production на SQLite.

## 0.23.4 - 2026-07-19

### Исправлено

- исправлен регрессионный тест запрета серверных действий для редактора под Livewire 4.2;
- тест больше не пытается продолжить Livewire-запрос после запрещённого `mount()`, когда корректный `403` не создаёт snapshot компонента;
- серверные методы создания GameServer и LoginServer по-прежнему вызываются напрямую в тесте и обязаны вернуть `403`, поэтому защита не ослаблена;
- рабочий код ролей и разрешений не изменялся.

## 0.23.3 - 2026-07-19

### Роли администраторов

- удалена промежуточная роль «Модератор», поскольку без отдельных инструментов модерации она дублировала части ролей администратора и редактора;
- в панели остаются три понятные роли: владелец, администратор и редактор;
- существующие учётные записи модераторов автоматически переводятся в редакторы при миграции;
- при автоматическом изменении роли увеличивается `session_version`, поэтому прежние сессии бывших модераторов завершаются;
- устаревшая роль удалена из выбора, описаний, переводов, фабрик, оформления и браузерных сценариев.

### Тесты

- добавлена регрессия миграции, проверяющая перевод только модераторов в редакторы и отзыв их сессий;
- тесты разрешений и выбора роли обновлены для трёхуровневой модели доступа.

## 0.23.2 - 2026-07-19

### Исправлено

- устранены два замечания Larastan `noUnnecessaryCollectionCall` в проверках последнего активного администратора и владельца;
- подсчёт заблокированных строк выполняется после locking-запроса без вызова `Collection::count()`, поэтому защита от конкурентного отключения сохраняется;
- логика ролей, разрешений и миграций не изменялась.

## 0.23.1 - 2026-07-19

### Исправлено

- устранены три замечания Laravel Pint в новых классах разграничения доступа и конфигурации приложения;
- пустые конструкторы приведены к принятому формату, импорты middleware упорядочены;
- логика ролей, разрешений и миграций не изменялась.

## 0.23.0 - 2026-07-19

### Роли администраторов

- добавлены четыре встроенные роли: владелец, администратор, модератор и редактор;
- владелец имеет полный доступ, администратор управляет рабочими разделами без доступа к владельцам и критическим настройкам, модератор управляет пользователями и контентом и просматривает остальные доступные разделы без изменения, редактор работает только с новостями и страницами;
- существующие административные учётные записи автоматически получают роль владельца после миграции, поэтому обновление не отнимает доступ;
- выбор роли сопровождается коротким описанием, а текущая роль отображается в списке, карточке и меню учётной записи;
- недоступные разделы скрываются из меню, но безопасность не зависит от интерфейса: маршруты, обычные формы и Livewire-действия управления серверами дополнительно проверяются на стороне PHP;
- для модератора настройки, почта, темы и подключения серверов открываются в режиме просмотра с заблокированными формами и серверным запретом записи;
- администратор может создавать и изменять только администраторов, модераторов и редакторов, но не может создавать владельцев или управлять их учётными записями;
- изменение роли завершает прежние сессии целевого администратора и записывается в журнал действий;
- последнего активного владельца нельзя отключить или понизить; собственную роль нельзя изменить через веб-интерфейс.

### Тесты

- добавлены регрессионные сценарии разрешений всех четырёх ролей, режима просмотра, защиты владельцев, запрета критических настроек и отзыва сессий после смены роли;
- браузерный smoke-тест проверяет отображение роли владельца и динамические описания ролей в форме администратора.

## 0.22.9 - 2026-07-19

### Единая система компонентов админ-панели

- сводные панели новостей, страниц, тем, пользователей, администраторов, аудита, безопасности и дашборда переведены на общий компонент `admin-overview`;
- карточные списки контента, пользователей, администраторов и тем получили единые поверхности, границы, радиусы, тени, состояния наведения и блоки действий;
- таблицы аудита, истории входов и активности пользователей используют общие компоненты `admin-table` и `admin-table-wrap`;
- фильтры пользователей и категории журнала приведены к общей системе панелей фильтрации и вторичных вкладок;
- заголовки карточек, пустые состояния, панели действий и страницы входа согласованы с основной светлой темой;
- каталог тем получил полноценную адаптивную карточную раскладку вместо набора разрозненных и устаревших классов;
- добавлены семантические CSS-токены успеха, предупреждения, ошибки и информационного состояния для будущей тёмной темы.

### Тесты

- добавлена функциональная регрессия общих компонентов и их использования в ключевых административных разделах;
- браузерный smoke-тест проверяет вычисленные поверхности новостей, пользователей, журнала действий и таблиц.

## 0.22.8 - 2026-07-19

### Административная панель

- кнопки изменения адреса панели и сохранения настроек мониторинга перенесены под соответствующие поля на отдельную строку;
- поля политики игровых аккаунтов получили полноценную вертикальную структуру: подпись, поле и пояснение больше не слипаются в одну строку;
- пояснение об общем лимите по всем LoginServer отображается под числовым полем отдельной строкой;
- парные поля минимальной и максимальной длины выровнены в две равные колонки с переходом в одну колонку на узких экранах.

### Тесты

- добавлена регрессионная проверка вертикальной раскладки действий и пояснений в административных настройках;
- браузерный smoke-тест проверяет фактическое расположение кнопок ниже полей и текста лимита ниже числового значения.

## 0.22.7 - 2026-07-19

### Административная панель

- верхние вкладки настроек и почты объединены общим компонентом и вынесены на отдельную светло-серо-голубую панель;
- активная вкладка использует основной синий цвет панели вместо отдельного тёмного оформления;
- карточки, панели действий, поля и кнопки получили согласованные границы, радиусы, лёгкие тени и состояния фокуса;
- вкладки боковой панели GameServer приведены к общей палитре, сохранив привычную форму вкладок документа;
- боковое меню и активные пункты получили более чёткую визуальную иерархию без изменения структуры навигации;
- адаптивное отображение вкладок сохранено: две колонки на узких экранах и одна колонка на телефонах.

### Основа будущей тёмной темы

- основные поверхности, текст, границы, акцент, тени и радиусы административной панели вынесены в CSS-переменные `--admin-*`;
- прежние переменные оставлены совместимыми алиасами, поэтому существующие компоненты и сторонние шаблоны административной части не ломаются;
- тёмная тема в этой версии не включена: 0.22.7 завершает и стабилизирует светлую тему.

### Тесты

- добавлен функциональный регрессионный тест общих CSS-токенов и компонентов вкладок;
- браузерный smoke-тест проверяет вычисленные цвета панелей и активных вкладок в настройках, почте и панели GameServer.

## 0.22.6 - 2026-07-19

### Исправлено

- обновлена устаревшая проверка системной навигации после переименования подписи с «Разделы системы» на «Разделы настроек»;
- устранено единственное падение `SystemSettingsTest`, блокировавшее `update.ps1` и `quality.ps1` при корректно работающем интерфейсе.

### Тесты

- рабочая логика и интерфейс не изменялись; исправлено только смысловое ожидание существующего регрессионного теста.

## 0.22.5 - 2026-07-19

### Административная панель

- группа «Система» в боковом меню заменена одним пунктом «Настройки»;
- страницы «Сайт», «Панель администратора», «Регистрация», «Игровые аккаунты», «Языки», «Безопасность» и «Системная информация» доступны через локальные вкладки внутри настроек;
- «Журнал действий» и будущие «Модули» оставлены отдельными пунктами, поскольку они не являются параметрами конфигурации;
- пункт «Настройки» остаётся активным на всех вложенных страницах `/settings/*`.

### Тесты и документация

- исправлены устаревшие ожидания названия «Основные» после переименования вкладки в «Сайт»;
- хрупкая проверка пробелов в HTML заменена проверкой структуры навигации;
- добавлены регрессионные сценарии единственного пункта «Настройки» на вложенной странице и отсутствия двойной подсветки в отдельном разделе почты;
- браузерная навигация и документация обновлены под единый раздел настроек.

## 0.22.4 - 2026-07-19

### Административная панель

- настройки игровых аккаунтов перенесены из группы «Серверы» в системные настройки, чтобы раздел не воспринимался как список аккаунтов игроков;
- вкладка «Основные настройки» переименована в «Сайт»;
- вкладка «Регистрация и аккаунты» сокращена до «Регистрация»;
- вкладка «Игровые аккаунты» расположена сразу после регистрации в меню и локальной навигации системы;
- пояснение к общему количеству игровых аккаунтов разделено на две строки для лучшей читаемости.

### Совместимость и тесты

- URL и имена маршрутов настроек игровых аккаунтов сохранены без изменений;
- добавлена регрессионная проверка размещения раздела в системном меню;
- браузерный smoke-сценарий проверяет переход на системную вкладку игровых аккаунтов и перенос пояснения на новую строку.

## 0.22.3 - 2026-07-19

### Административная панель

- меню перераспределено по понятным группам «Контент», «Оформление», «Серверы», «Пользователи», «Почта» и «Система»;
- основные настройки, панель администратора, регистрация и аккаунты, языки, безопасность и системная информация объединены локальными системными вкладками;
- адрес панели управления и мониторинг серверов перенесены из диагностической страницы в отдельную вкладку «Панель администратора»;
- «Системная информация» теперь содержит только диагностику окружения, состояние компонентов и безопасный отчёт;
- администраторы перенесены в группу пользователей, а темы — в отдельную группу оформления.

### Совместимость и качество

- прежние PUT-адреса `/settings/system/monitoring` и `/settings/system/admin-path` сохранены как совместимые алиасы;
- `quality.ps1` дополнительно собирает и очищает кэш маршрутов, чтобы ошибки route-групп обнаруживались до релиза;
- документация и браузерные сценарии обновлены под новую структуру меню.

### Тесты

- добавлены три функциональных сценария для новой страницы панели администратора и совместимости старых endpoint мониторинга и адреса панели;
- добавлен браузерный smoke-сценарий перехода между вкладками панели администратора и системной информации.

## 0.22.2 - 2026-07-19

### Исправлено

- форматирование группы административных маршрутов приведено к правилам Laravel Pint;
- устранена ошибка `statement_indentation` в `routes/admin.php`, блокировавшая `quality.ps1`.

### Тесты

- новые функциональные тесты не требуются: изменение затрагивает только форматирование, а регрессией служит обязательный запуск Pint через `quality.ps1`.

## 0.22.1 - 2026-07-19

### Исправлено

- ограничение `adminPath` теперь регистрируется через `Route::pattern`, а не через неподдерживаемый вызов `where()` у группы маршрутов;
- устранён `TypeError array_merge(): Argument #2 must be of type array, string given`, из-за которого `composer install`, `artisan package:discover` и обновление 0.22.0 не запускались.

### Тесты

- добавлена регрессионная проверка фактического ограничения параметра `adminPath` в загруженной коллекции маршрутов.

## 0.22.0 - 2026-07-19

### Добавлено

- администратор может заменить стандартный `/admin` на адрес вида `/admin-<суффикс>` с обязательным подтверждением и автоматическим переходом;
- добавлена команда `kaevcms:admin-path` для просмотра, установки и аварийного сброса адреса панели;
- рядом с настройкой адреса выводится информационная подсказка с командами восстановления доступа.

### Стабилизация ядра

- маршруты разделены на `routes/public.php`, `routes/account.php` и `routes/admin.php` без изменения публичных route names;
- крупный `SettingsController` разделён на контроллеры общих, системных, языковых, регистрационных и почтовых настроек;
- новости, страницы и произвольные письма используют общую основу `SafeHtmlSanitizer` с независимыми безопасными профилями;
- публичные темы и шаблоны кабинета используют общий `ThemeValidator` с проверкой manifest, обязательных файлов, совместимости и выхода за допустимые каталоги;
- ранняя GET-проверка ссылки восстановления пароля ограничена отдельным throttle.

### Безопасность и совместимость

- служебный параметр динамического административного пути удаляется middleware до вызова контроллера и не может заменить настоящий slug или идентификатор маршрута;
- старый административный адрес после смены возвращает `404` и не раскрывает новый путь;
- обновление сохраняет суффикс панели в `cms_settings`, а старый монолитный контроллер удаляется установочным скриптом;
- миграции базы данных и изменения Composer не требуются.

### Тесты

- добавлены одиннадцать регрессионных сценариев для адреса панели, Artisan-восстановления, позиционных параметров URL, параметров контроллеров, структуры маршрутов, разделения настроек, общего санитайзера и валидатора тем.

## 0.21.8 - 2026-07-19

### Исправлено

- локализованная ссылка восстановления пароля теперь получает настоящий токен из параметра `token`, а не код языка из префикса URL;
- устранена ситуация, когда рабочая ссылка вида `/ru/reset-password/{token}` сразу считалась недействительной.

### Тесты

- добавлен полный регрессионный сценарий локализованной ссылки: открытие формы и успешная смена пароля через `/ru/reset-password`.

## 0.21.7 - 2026-07-19

### Исправлено

- токены восстановления пароля теперь создаются и проверяются в UTC, независимо от часового пояса веб-процесса, Scheduler или queue worker;
- устранена ситуация, когда ссылка могла считаться просроченной сразу после получения при расхождении часовых поясов процессов;
- исправлено форматирование профильного теста режима доставки по правилам Laravel Pint.

### Тесты

- добавлен сквозной регрессионный сценарий: токен создаётся в одном часовом поясе, проверяется в другом, открывает форму и успешно меняет пароль.

## 0.21.6 - 2026-07-18

### Исправлено

- очередь больше не отправляет устаревшее письмо восстановления, если после его постановки был создан новый токен;
- ссылка восстановления проверяется сразу при открытии формы, а не только после ввода нового пароля;
- email на форме восстановления зафиксирован за токеном и не может быть незаметно заменён автозаполнением браузера;
- во вкладке доставки выводится предупреждение, если `APP_URL` отличается от адреса открытой административной панели.

### Тесты

- добавлены регрессионные сценарии актуального и устаревшего токена в очереди, ранней проверки ссылки, фиксированного email и предупреждения о несовпадении `APP_URL`.

## 0.21.5 - 2026-07-18

### Изменено

- настройки SMTP и режимы автоматической доставки разделены на вкладки «Подключение» и «Доставка»;
- основная вкладка почты снова содержит только SMTP, отправителя и тестовое письмо;
- добавлена инструкция настройки очереди на Linux VDS через Supervisor и альтернативный Cron Scheduler.

### Тесты

- обновлена регрессионная проверка: режимы доставки доступны только на отдельной вкладке, а SMTP-вкладка не перегружена ими.

## 0.21.4 - 2026-07-18

### Исправлено

- исправлены два нарушения форматирования Laravel Pint в расписании очереди почты и профильных тестах режима доставки;
- функциональность почтовых режимов не изменялась.

### Тесты

- сохранён полный набор тестовых сценариев почтовой доставки; исправлено только оформление тестового класса.

## 0.21.3 - 2026-07-18

### Исправлено

- обновление теперь гарантированно удаляет старые `apply-*.ps1`, каталог `preview/` и неиспользуемую заглушку настроек до запуска тестов;
- ошибки очистки больше не скрываются: обновление останавливается с понятным сообщением вместо выпуска установки с устаревшими файлами.

### Тесты

- добавлена регрессионная проверка, что `update.ps1` содержит обязательную очистку устаревших артефактов и выполняет её до `php artisan test`.

## 0.21.2 - 2026-07-18

### Добавлено

- в настройках почты доступны три понятных режима: синхронный, асинхронный и асинхронный с очередью в базе данных;
- при первом выборе асинхронного режима CMS автоматически запускает реальную проверку и включает режим только после её успешного выполнения;
- режим с базой сохраняет задания в стандартной таблице Laravel `jobs`, поддерживает повторные попытки и переживает перезапуск приложения;
- Laravel Scheduler ежеминутно запускает одноразовый обработчик очередей `mail-probe` и `mail`, который завершается после их опустошения;
- добавлен информационный блок с кратким сравнением режимов и отдельным состоянием проверки каждого асинхронного варианта;
- административный дашборд различает оба асинхронных режима.

### Надёжность

- переход обратно на синхронный режим отменяет ожидающее автоматическое включение проверяемого режима;
- неудачная отправка из сохраняемой очереди отмечается ошибкой только после исчерпания повторных попыток;
- тест режима с базой ожидает Scheduler или worker до 90 секунд, после чего безопасно оставляет синхронную отправку.

### Тесты

- добавлены проверки реального database-коннектора, автоматического включения обоих асинхронных режимов, отмены отложенного включения, Scheduler, сохраняемой очереди и отображения на дашборде.

## 0.21.1 - 2026-07-18

### Тесты

- добавлена регрессионная проверка без `Queue::fake()`, которая реально разрешает встроенное Laravel-подключение `background` и подтверждает использование `BackgroundQueue`;
- функциональность фоновой отправки не изменялась.

## 0.21.0 - 2026-07-18

### Добавлено

- в настройках почты появился безопасный выбор синхронной и фоновой отправки автоматических писем кабинета;
- перед включением фонового режима CMS запускает реальную тестовую задачу Laravel и не сохраняет режим, если отдельный PHP-процесс на сервере не выполнился;
- фоновый режим использует встроенное подключение Laravel `background` и не требует постоянно работающего queue worker;
- подтверждение email, восстановление пароля и уведомление о смене пароля отправляются через единый диспетчер с фиксацией состояния доставки;
- административный дашборд показывает режим, ожидающие письма, ошибки за семь дней, самое старое ожидающее и последнюю успешную отправку;
- добавлена таблица `mail_deliveries` для безопасного контроля автоматических писем без хранения текста, токенов и ссылок.

### Безопасность и совместимость

- синхронный режим остаётся стандартным и работает без дополнительной настройки сервера;
- смена режима не влияет на тестовые, произвольные и шаблонные письма администратора: они остаются синхронными, чтобы результат был виден сразу;
- если проверка фонового процесса не завершилась за 15 секунд, CMS отмечает её неуспешной и сохраняет либо восстанавливает синхронный режим;
- фоновые письма разделены по собственному подключению и не зависят от значения `QUEUE_CONNECTION` в `.env`.

### Тесты

- добавлены десять регрессионных проверок интерфейса, запрета включения без теста, фоновой пробы, автоматического возврата к синхронному режиму, отправки и мониторинга писем.

## 0.20.3 - 2026-07-18

### Изменено

- актуализированы README, дорожная карта и техническая документация по модулям, системной информации, секретам и игровым драйверам;
- удалён независимый статический каталог `preview/`, который дублировал оформление темы и не использовался приложением;
- удалён неиспользуемый шаблон `resources/views/admin/settings/placeholder.blade.php`.

### Тесты

- добавлена проверка согласованности `VERSION`, заголовка README, первой записи CHANGELOG, `update.ps1` и актуального `apply-<version>.ps1`;
- добавлена проверка отсутствия удалённых демонстрационных файлов в релизе.

## 0.20.2 - 2026-07-18

### Исправлено

- каталог персонажей личного кабинета кэширует успешный ответ внешней игровой базы на две минуты отдельно для каждого GameServer и игрового аккаунта;
- после ошибки подключения повторные запросы к той же базе приостанавливаются на 30 секунд, включая предварительную загрузку через `wire:navigate.hover`.

### Тесты

- добавлены две регрессионные проверки кэша успешного ответа и cooldown после ошибки внешней базы.

## 0.20.1 - 2026-07-18

### Исправлено

- исправлен `BrandingTest`: результат `Artisan::output()` теперь сохраняется перед несколькими проверками, поскольку чтение очищает консольный буфер;
- проверка совместимости дополнительно охватывает устаревший алиас `cms:about` наряду с `l2forge:about`.

### Тесты

- исправлен один существующий тест и добавлен один отдельный регрессионный сценарий консольных алиасов.

## 0.20.0 - 2026-07-18

### Изменено

- проект переименован из **L2Forge CMS** в **KaevCMS** во всех пользовательских интерфейсах, стандартных темах, языковых пакетах, документации, PowerShell-скриптах и метаданных пакетов;
- Composer-пакет переименован в `kaevcms/cms`, а пакет браузерных тестов — в `kaevcms-browser-tests`;
- основные консольные команды получили пространство имён `kaevcms:*`; старые команды `l2forge:*` сохранены как совместимые алиасы для существующих заданий и инструкций;
- внутренний класс версии переименован в `App\Support\KaevCMS`, административные JavaScript-глобалы и технические имена новых временных ресурсов переведены на новый бренд;
- новые установки используют значения `KaevCMS`, `kaevcms` и `© 2026 KaevCMS` по умолчанию.

### Совместимость обновления

- миграция `2026_07_18_000200_rebrand_l2forge_to_kaevcms.php` меняет в `cms_settings` только точные заводские значения старого бренда; собственные названия сайта, подписи подвала и имя отправителя не изменяются;
- `update.ps1` аналогично обновляет только точные старые значения `APP_NAME`, `SITE_NAME`, локализованных названий, текста подвала и `MAIL_FROM_NAME` в `.env`;
- таблицы, маршруты, пользователи, игровые аккаунты, персонажи, подключения и настройки администраторов не переименовываются и не пересоздаются;
- для существующей установки `update.ps1` сохраняет прежнее имя session-cookie через `SESSION_COOKIE=l2forge_session`, поэтому само переименование не должно принудительно завершать активные сеансы; новые установки используют `kaevcms_session`;
- старый класс `App\Support\L2Forge` удалён патчем после перехода на новый класс версии.

### Тесты

- добавлены четыре регрессионные проверки нового бренда, безопасной миграции заводских значений, новых метаданных пакета и совместимости консольного алиаса `l2forge:about`;
- существующие тесты консольных операций переведены на основные команды `kaevcms:*`.

## 0.19.1 - 2026-07-18

### Исправлено

- тесты личного кабинета теперь создают действительно подтверждённых пользователей через штатную фабрику, а не пытаются массово заполнить защищённое поле `email_verified_at`;
- страница записи журнала снова использует отдельный заголовок **«Подробности»**, не меняя подпись кнопок списка;
- порядок импортов в `GameAccountCabinetTest` приведён к требованиям Laravel Pint.

### Тесты

- исправлены причины падения существующих проверок навигации кабинета, активного шаблона и журнала действий;
- новые тесты не добавлялись.

## 0.19.0 - 2026-07-18

### Добавлено

- независимая система сменных шаблонов личного кабинета с каталогами `account-themes/<slug>` и `public/account-themes/<slug>`;
- `AccountThemeManager` с проверкой slug, `theme.json`, обязательных Blade-файлов, CSS/JavaScript, preview и совместимости с версией CMS;
- административный раздел **Сайт → Шаблоны личного кабинета** с предпросмотром, диагностикой и безопасной активацией;
- встроенный шаблон `L2 Obsidian Luxury` с графитовой палитрой, золотым акцентом, новой стартовой панелью, метриками, карточками игровых аккаунтов, каталогом персонажей и адаптивными формами;
- документация контракта шаблонов в `docs/ACCOUNT_THEMES.md`.

### Изменено

- контроллеры личного кабинета и Livewire-компоненты используют namespace `account-theme::*`, а не представления внутри ядра;
- публичная тема сайта и шаблон личного кабинета выбираются независимо и хранятся в разных настройках;
- отсутствующие представления пользовательского шаблона автоматически берутся из встроенного `luxury`;
- старая папка `resources/views/account`, старые Livewire-представления кабинета и ресурсы `public/assets/account` удалены;
- постоянная Livewire-оболочка, `wire:navigate`, история браузера и реактивные действия сохранены в новом дизайне;
- верхняя панель получила подготовленное, но явно неактивное место будущего баланса монет без фиктивного значения.

### Безопасность

- повреждённый, удалённый или несовместимый шаблон кабинета не активируется, а при проблеме с ранее выбранным пакетом CMS использует штатный `luxury`;
- шаблоны содержат только представления и ресурсы: создание аккаунтов, чтение персонажей, смена паролей, проверка владельца и будущие донат-операции остаются в ядре;
- загрузка ZIP через административную панель не добавлялась до реализации защищённой распаковки.

### Тесты

- добавлены шесть PHPUnit-проверок управления шаблонами, независимой активации, активных ресурсов, постоянной оболочки и отсутствия устаревших файлов;
- добавлена браузерная проверка нового шаблона после SPA-переходов и Livewire-переключения каталога персонажей.

## 0.18.0 - 2026-07-18

### Добавлено

- постоянная Livewire-оболочка личного кабинета с сохраняемыми боковой панелью и верхним профилем;
- отдельная страница `/account/game-accounts` и её локализованные маршруты для полноценной навигации раздела;
- SPA-переходы `wire:navigate` и предварительная загрузка страниц при наведении на основные ссылки кабинета;
- компактное мобильное меню, выпадающий профиль пользователя и визуальный индикатор фоновой навигации;
- браузерная проверка сохранения оболочки при переходах, возврате назад и переходе вперёд.

### Изменено

- Livewire теперь подключается один раз в общем шаблоне каждой страницы личного кабинета;
- ссылки обзора, игровых аккаунтов, создания аккаунта, управления аккаунтом и каталога персонажей больше не вызывают полную перезагрузку страницы;
- список игровых аккаунтов доступен как самостоятельный раздел, при этом обзор по-прежнему содержит краткий список и каталог персонажей;
- интерфейс кабинета получил более цельное тёмное оформление, устойчивую ширину страницы и адаптивную боковую панель.

### Тесты

- добавлены три PHPUnit-проверки постоянной оболочки, маршрутов списка аккаунтов и SPA-ссылок;
- добавлена одна Playwright-проверка сохранения DOM боковой и верхней панелей при навигации и истории браузера.

## 0.17.0 - 2026-07-18

### Добавлено

- единый каталог персонажей на главной странице личного кабинета без отдельного пункта бокового меню;
- два режима отображения: иерархия «По серверам» и плоский список «Все персонажи»;
- поиск по имени, классу, клану, серверу и игровому аккаунту, фильтры по серверу, аккаунту и онлайн-статусу, а также сортировка плоского списка;
- сворачивание серверов и игровых аккаунтов, постоянное скрытие ненужных групп и отдельное восстановление скрытых серверов и аккаунтов;
- общая строка персонажа с уровнем, текущим классом, расой, полом, кланом, онлайном, игровым временем, PvP, PK, кармой, дворянством и статусом героя;
- таблица `user_character_preferences` для хранения выбранного режима и скрытых групп пользователя;
- браузерная проверка сохранения выбранного режима после перезагрузки страницы.

### Изменено

- `/account` всегда остаётся стартовой страницей кабинета, даже когда у пользователя только один игровой аккаунт;
- при одном доступном сервере и аккаунте обе группы сразу раскрываются;
- плоский список подписывает каждый персонаж названием GameServer и логином игрового аккаунта и по умолчанию включает персонажей из скрытых групп;
- страница игрового аккаунта остаётся местом управления аккаунтом и смены пароля, а общий каталог используется для просмотра персонажей и будущих действий с ними;
- чтение персонажей Mobius Interlude дополнено безопасными полями профиля и связями с кланом и текущими героями; удалённые и привилегированные персонажи исключаются.

### Безопасность

- каталог строится только из игровых аккаунтов текущего авторизованного пользователя;
- скрытие меняет только представление CMS и не удаляет игровые аккаунты или персонажей;
- будущие операции с донат-монетами смогут использовать идентифицированную строку «сервер → аккаунт → персонаж» без доверия данным из браузера.

### Тесты

- добавлены три PHPUnit-проверки режимов каталога, постоянного скрытия групп и фильтрации общего списка;
- добавлена одна Playwright-проверка сохранения режима «Все персонажи»;
- расширена проверка `BrowserTestSeeder` для тестового игрока и игрового аккаунта.

## 0.16.1 - 2026-07-18

### Исправлено

- настройки рейтингов остаются видимыми на вкладке «Статистика» при выключенной публикации, но становятся неактивными и не теряют сохранённые значения;
- включение режима обслуживания из Livewire корректно открывает вкладку «Разное», поэтому поля сообщений для всех активных языков сразу доступны;
- драйвер Mobius Interlude снова применяет индивидуальный SQL-лимит для рейтингов уровня, PvP, PK и игрового времени;
- устранено замечание Pint о неиспользуемом параметре замыкания в игровом драйвере.

### Тесты

- новые тесты не добавлялись: исправления закрывают четыре уже существующие регрессионные проверки вкладок, обслуживания, динамических языков и независимых лимитов.

## 0.16.0 - 2026-07-18

### Добавлено

- настройки каждого GameServer разделены на вкладки «Основное», «Статистика» и «Разное»;
- для рейтингов уровня, PvP, PK и игрового времени добавлены независимые лимиты от 1 до 100;
- текущие герои и владельцы замков выводятся полностью без общего ограничения;
- неподдерживаемые владельцы крепостей явно отмечаются в настройках драйвера Interlude;
- при ошибке проверки форма автоматически открывает вкладку с проблемным полем.

### Изменено

- режим обслуживания и дополнительные сетевые настройки перенесены на вкладку «Разное»;
- старый общий лимит статистики при обновлении переносится во все четыре новых лимита;
- для новых игровых серверов каждый лимит по умолчанию равен 10.

### Тесты

- добавлены проверки вкладок GameServer, независимых лимитов, полного списка героев и миграции старого общего лимита;
- браузерная проверка подтверждает переключение вкладок в реальной Livewire-панели.

## 0.15.1 - 2026-07-18

### Исправлено

- локализованные маршруты статистики теперь используют отдельные методы контроллера и корректно связывают параметр GameServer после префикса языка;
- тест личного кабинета больше не ожидает отображение игрового времени до отдельного этапа обновления карточек персонажей;
- устранено замечание Larastan о лишнем `array_values()` для уже нормализованного списка результатов.

### Тесты

- существующая проверка локализованной статистики расширена маршрутом общего списка и маршрутом выбранного игрового мира.

## 0.15.0 - 2026-07-17

### Добавлено

- универсальный read-only слой GameWorld для чтения игровых данных через независимые драйверы;
- первый игровой драйвер статистики `L2J Mobius CT0 Interlude`;
- публичный раздел `/statistics` с отдельными игровыми мирами и рейтингами по уровню, PvP, PK и времени в игре;
- публичные списки текущих героев и владельцев замков;
- настройки публикации и отдельных разделов статистики для каждого GameServer;
- пятиминутное кеширование успешных выборок и короткая защита игровой базы от повторных запросов при недоступности;
- версионирование CSS и JavaScript активной публичной темы для корректного обновления браузерного кеша.

### Безопасность

- статистика по умолчанию выключена и включается администратором отдельно для каждого игрового мира;
- из рейтингов исключаются удалённые и привилегированные персонажи;
- публичные запросы не выбирают логины игровых аккаунтов, IP, пароли и другие служебные данные;
- глобальное скрытие публичного онлайна применяется и к рейтингу персонажей.

### Тесты

- добавлены регрессионные проверки драйвера, рейтингов, героев, замков, локализованных маршрутов, кеша, защитного cooldown, приватности онлайна, административных настроек, миграции и версионирования ресурсов темы.

## 0.14.5 - 2026-07-17

### Исправлено

- `package-lock.json` браузерных тестов больше не содержит адрес внутреннего npm-зеркала сборочной среды;
- зависимости Playwright загружаются из публичного реестра `registry.npmjs.org`.

### Тесты

- добавлена регрессионная проверка переносимости npm lock-файла.

## 0.14.4 - 2026-07-17

### Исправлено

- `BrowserTestSeeder` больше не вызывает `env()` вне каталога `config`, поэтому проверка Larastan проходит и конфигурация корректно работает при кэшировании Laravel;
- учётные данные тестового администратора браузерных проверок вынесены в `config/browser_tests.php`.

### Тесты

- добавлен регрессионный тест создания браузерного администратора из конфигурации.

## 0.14.3 - 2026-07-17

### Исправлено

- DOM-проверка открытой группы бокового меню приведена к стилю Laravel Pint без изменения логики теста.

## 0.14.2 - 2026-07-17

### Исправлено

- тест открытой группы бокового меню больше не зависит от количества пробелов, которое Blade выводит между HTML-атрибутами;
- проверка по-прежнему подтверждает, что атрибут `open` установлен именно на группе «Сайт».

## 0.14.1 - 2026-07-17

### Изменено

- управление профилями GameServer окончательно оставлено только в Livewire-панели; устаревшие HTTP-маршруты создания, изменения и удаления удалены;
- страничные JavaScript-файлы подключены к единому жизненному циклу `page-lifecycle.js` и безопасно переинициализируются после `wire:navigate`;
- аудит LoginServer/GameServer больше не сохраняет адреса, имена баз и учётные записи; вместо значений фиксируются только признаки настройки и изменения.

### Добавлено

- Playwright smoke-тесты повторного открытия редактора, QR-кода 2FA, истории браузера и сохранённого состояния бокового меню;
- отдельный разработческий запуск браузерных тестов через `browser-quality.ps1`;
- регрессионная проверка отсутствия инфраструктурных реквизитов в журнале действий.

### Исправлено

- устранена возможность накопления или потери обработчиков страничного JavaScript при повторных SPA-переходах.

## 0.14.0 - 2026-07-17

### Добавлено

- постоянная боковая панель админки через Livewire `@persist`;
- предварительная загрузка разделов при наведении на пункты меню;
- два регрессионных теста устойчивой оболочки и навигационного состояния.

### Изменено

- активный пункт меню обновляется на клиенте через `wire:current`;
- общий навигационный скрипт загружается один раз из `<head>` и отслеживается по версии;
- сохраняются прокрутка боковой панели и ручное состояние групп;
- ширина оболочки, место под полосу прокрутки и минимальная высота контента стабилизированы;
- переход сопровождается коротким затуханием только рабочей области и тонким индикатором загрузки.

## 0.13.39 - 2026-07-17

### Исправлено

- тест плавной навигации больше не зависит от хешированного каталога Livewire 4;
- проверяется стабильное имя скрипта и признак навигационной загрузки Livewire.

### Тесты

- исправлен один существующий регрессионный тест без ослабления проверки функциональности.

## 0.13.38 - 2026-07-17

### Добавлено

- плавная навигация по административной панели через Livewire `wire:navigate`;
- общий индикатор загрузки в цвете административной панели.

### Изменено

- Livewire-ресурсы подключаются единообразно для всей административной панели;
- внутренние ссылки, карточки, вкладки и пагинация используют навигацию без полной перезагрузки;
- JavaScript редакторов, диалогов, локализации и системных страниц адаптирован к повторной инициализации после переходов;
- фоновый мониторинг дашборда и временные URL изображений очищаются при переходе на другую страницу.

### Тесты

- добавлено два регрессионных теста плавной навигации и совместимости административных JavaScript-файлов.

## 0.13.37 - 2026-07-17

### Изменено

- переключатель публичного онлайна перенесён из основных настроек непосредственно над карточками игровых серверов;
- публичный статус работающего игрового мира переименован с «В игре» на «Доступен».

### Исправлено

- при отключённом публичном онлайне полностью удаляются число игроков и текст «Онлайн временно недоступен», включая публичный JSON-ответ.

### Тесты

- добавлен регрессионный тест управления публичным онлайном из раздела игровых серверов; расширен тест полного скрытия публичного блока.

## 0.13.36 - 2026-07-17

### Исправлено

- устранено перекрытие свойства Livewire локальной переменной карточки GameServer;
- поля локализованных сообщений обслуживания теперь действительно появляются сразу после включения режима;
- восстановлено прохождение двух существующих регрессионных тестов обслуживания и динамического языка.

## 0.13.35 - 2026-07-17

### Исправлено

- переключатель режима обслуживания переведён на явное Livewire-действие, поэтому поля локализованных сообщений сразу появляются и скрываются без перезагрузки;
- восстановлено прохождение двух регрессионных тестов режима обслуживания и динамически добавленного языка.

## 0.13.34 - 2026-07-17

### Изменено

- режим обслуживания упрощён: отдельное поле даты окончания удалено, время при необходимости указывается в сообщении;
- поле сообщения появляется только после включения режима обслуживания;
- сообщения обслуживания синхронизированы с динамическим списком включённых языков, включая добавленные языковые пакеты.

### Исправлено

- устранена ошибка PHPStan `nullCoalesce.offset` в `HomeController`;
- локализованное сообщение обслуживания корректно сохраняется и показывается для текущего языка сайта.

### Тесты

- добавлено два регрессионных теста: динамический язык сообщения обслуживания и безопасное удаление устаревшего поля даты.

## 0.13.33 - 2026-07-17

### Исправлено

- форматирование `SettingsController` и `GameServerSettings` полностью приведено к требованиям Laravel Pint.

## 0.13.32 - 2026-07-17

### Добавлено

- ручной режим обслуживания GameServer с оранжевым публичным статусом, необязательным временем окончания и локализованным сообщением;
- глобальная настройка показа количества игроков на публичном сайте;
- пять регрессионных тестов режима обслуживания, скрытия публичного онлайна, Livewire-сохранения настроек и безопасной миграции.

### Изменено

- мониторинг БД, службы и административный онлайн продолжают работать во время обслуживания;
- при отключённом публичном онлайне число игроков скрывается также из публичного JSON-ответа.

### Исправлено

- форматирование `SettingsController` приведено к требованиям Laravel Pint.

## 0.13.31 - 2026-07-17

### Добавлено

- отдельный сохранённый результат проверки базы данных для LoginServer и GameServer: `database_status`, безопасный код ошибки и время последней проверки;
- сервис `ServerDatabaseState`, который считает сервер настроенным только при успешном подключении, готовом драйвере и совместимой схеме;
- реактивная Livewire-форма смены игрового пароля с выводом ошибок без перезагрузки страницы и сохранённым HTTP-вариантом на случай недоступного JavaScript;
- регрессионные тесты ложного онлайна при открытом порте и недоступной БД, автоматической проверки случайных реквизитов, реактивной смены пароля и безопасной миграции существующих серверов.

### Изменено

- мониторинг проверяет не только порты служб, но и реальные подключения к внешним БД; административный дашборд показывает состояние БД и службы отдельно;
- публичный игровой мир получает статус «В игре» только при рабочей БД и службе GameServer, а также рабочей БД и службе связанного LoginServer;
- после сохранения реквизитов LoginServer или подключения GameServer CMS автоматически запускает проверку БД и записывает фактический статус;
- ссылки разделов в подвале выводятся колонками по три строки вместо одной неограниченной вертикальной колонки;
- раздел очистки журналов объясняет, почему счётчик равен нулю и кнопка недоступна до появления записей старше настроенного срока хранения.

### Исправлено

- непроверенные или случайные реквизиты LoginServer больше не отображаются зелёным статусом «Настроено»;
- доступный порт службы больше не создаёт ложный публичный онлайн и ложный общий онлайн при отсутствующей или несовместимой БД;
- ошибки смены игрового пароля больше не требуют полной перезагрузки страницы;
- ручная проверка сохранённого сервера теперь обновляет тот же постоянный статус БД, который используется мониторингом и карточками.

## 0.13.30 - 2026-07-17

### Добавлено

- единый прикладной слой `LoginServerAdministration` и `GameServerAdministration` для сохранения, проверки подключения и удаления серверов;
- сервис `ServerAuditValues`, формирующий общий безопасный снимок LoginServer/GameServer без раскрытия паролей;
- регрессионные тесты сохранения пароля и служебного адреса LoginServer, сохранения подключения при изменении карточки GameServer и переназначения аккаунтов при переносе мира на другой LoginServer.

### Изменено

- Livewire-компоненты `LoginServerManager` и `GameServerManager` оставлены ответственными только за состояние формы, валидацию и пользовательские сообщения; транзакции, переназначение связей и аудит перенесены в серверные сервисы;
- `LoginServerController`, `GameServerController` и `GameServerConnectionController` используют тот же прикладной слой, что и Livewire, вместо собственных реализаций сохранения, тестирования и удаления;
- обновление только названия, рейтов, хроники и режима GameServer явно сохраняет существующее подключение, а подключение или отключение передаются отдельным режимом операции;
- документация архитектуры и тестирования описывает единый путь административных операций серверов.

### Исправлено

- совместимый HTTP-маршрут изменения подключения GameServer теперь выполняет то же безопасное переназначение игровых аккаунтов и сброс снимка мониторинга, что и актуальная Livewire-панель;
- обновление LoginServer через старую форму больше не может случайно потерять отдельно сохранённые `service_host` и `service_port`, отсутствующие в её payload;
- устранено расхождение аудита между Livewire и совместимыми HTTP-маршрутами: одна операция записывает одно событие через общий сервис.

## 0.13.29 - 2026-07-17

### Добавлено

- фабрики `User`, `Admin`, `LoginServer`, `GameServer` и `UserGameAccount` с согласованными значениями по умолчанию и состояниями мониторинга `online`, `offline`, `stale`;
- общий тестовый concern `InteractsWithServerFixtures`, который удаляет историческую начальную строку GameServer и создаёт ровно одну согласованную пару LoginServer/GameServer;
- регрессионный конкурентный сценарий: между предварительной и транзакционной проверками квоты появляется другая связь, а финальная проверка всё равно блокирует создание лишнего аккаунта;
- руководство `docs/development/TESTING.md` по фабрикам, изоляции серверных fixtures, временно скрытым аккаунтам и проверке конкурентных инвариантов.

### Изменено

- критические тесты мониторинга и личного кабинета переведены на явные фабрики и больше не зависят от демонстрационной строки, создаваемой исторической миграцией;
- документация архитектуры приведена к фактической реализации: публичный статус и онлайн формируются через `ServerStatusOverview`, а старый `GameServerAdapter` остаётся только источником рейтинга персонажей;
- правила качества теперь требуют изолированных fixtures для сценариев точного количества серверов, удаления последнего мира и переназначения игровых аккаунтов.

### Исправлено

- устранён риск повторного появления ложных падений `ServerMonitoringTest` при изменении миграций, seeders или начальных данных;
- тест повторной проверки квоты явно подтверждает защиту от логической гонки, которую нельзя надёжно воспроизвести параллельными SQLite-запросами в памяти.

## 0.13.28 - 2026-07-16

### Исправлено

- лимит игровых аккаунтов теперь считается по всем связям пользователя, включая временно скрытые записи без доступного GameServer; удаление последнего игрового мира больше не позволяет создать лишний аккаунт сверх установленного лимита;
- создание игрового аккаунта повторно проверяет лимит и уникальность связи внутри транзакции с блокировкой пользователя и выбранной серверной пары, поэтому параллельные запросы не обходят квоту;
- прямое открытие единственного аккаунта учитывает скрытые связи: при сочетании видимого и временно недоступного аккаунта пользователь видит общий кабинет, точный счётчик и предупреждение;
- удаление последнего GameServer с привязанными аккаунтами требует отдельного подтверждения с точным числом затронутых записей; если последствия изменились до удаления, CMS запрашивает подтверждение повторно;
- устаревший HTTP-обработчик удаления GameServer больше не может обойти подтверждение и оставить игровые аккаунты недоступными без предупреждения;
- устранены восемь ошибок Larastan/PHPStan в `MySqlGameServerOnlineCounter`, `ServerMonitorCoordinator` и `ServerStatusOverview`: проверка конфигурации онлайна перенесена на границу `mixed`, тип блокировки заменён контрактом, а вычисление последней даты больше не зависит от инвариантности `Collection`.

### Добавлено

- сервис квоты игровых аккаунтов с отдельным смысловым запросом `gameAccountsCountingTowardLimit()`;
- расчёт последствий удаления GameServer и защищённый отпечаток подтверждения;
- регрессионные тесты скрытых аккаунтов, опасного удаления последнего GameServer, изменения последствий между подтверждением и выполнением и защиты устаревшего endpoint.

## 0.13.27 - 2026-07-16

### Исправлено

- три теста мониторинга больше не учитывают автоматически созданную миграцией демонстрационную строку GameServer: тестовый helper очищает серверные таблицы перед созданием изолированной пары LoginServer/GameServer;
- проверка сохранённого интервала, длинного срока актуальности и команды `l2forge:servers-monitor` теперь тестирует только подготовленные записи и не получает ложный `isDue()` от постороннего GameServer с пустым `monitor_checked_at`;
- `ServerMonitorStatusController`, `GameServer` и `LoginServer` приведены к текущему Laravel Pint: полностью квалифицированные типы Carbon заменены импортами, поэтому `pint --test` больше не останавливается на этих трёх файлах.

## 0.13.26 - 2026-07-16

### Исправлено

- определение срока следующей проверки больше не зависит от SQL-сравнения локальных дат: время последнего мониторинга сравнивается по Unix timestamp после штатного Eloquent-cast, что устраняет ложное состояние «просрочено» в SQLite и при отличающихся часовых поясах;
- сохранённый в админке интервал мониторинга сразу применяется в текущем запросе и команда `l2forge:servers-monitor` корректно пропускает ещё свежий снимок;
- срок актуальности статуса и онлайна корректно учитывает интервалы 2 и 5 минут без преждевременного перехода в состояние «Статус уточняется»;
- тест значения по умолчанию больше не зависит от локального `SERVER_MONITOR_REFRESH_INTERVAL_SECONDS` в `.env` разработчика;
- Argon2id-регрессия теперь пропускается на сборках PHP без `PASSWORD_ARGON2ID`, вместо падения на системе, где режим `auto` штатно выбрал bcrypt.

## 0.13.25 - 2026-07-16

### Добавлено

- в разделе «Система → Системная информация» появилась компактная настройка интервала обновления статуса серверов с безопасными вариантами 30 секунд, 1 минута, 2 минуты и 5 минут;
- рядом с параметром добавлена доступная подсказка, открывающаяся при наведении мыши или фокусе с клавиатуры и объясняющая использование сохранённого результата между проверками;
- настройка хранится в `cms_settings` и применяется к автоматическому обновлению публичной главной, административного дашборда, JSON-endpoint и запуску команды через Laravel Scheduler без изменения `.env`;
- добавлены регрессионные тесты сохранения, валидации, применения интервала и согласованности срока актуальности снимка.

### Изменено

- минимальный интервал обновления ограничен 30 секундами, а пользователю доступен только фиксированный безопасный список вместо произвольного числа;
- защитное время атомарной блокировки параллельного опроса остаётся внутренним параметром и теперь не может быть меньше 150 секунд; значение по умолчанию сохранено равным 300 секундам;
- срок актуальности статуса и онлайна автоматически учитывает выбранный интервал, поэтому режим 5 минут не переводит свежий снимок в серое состояние раньше следующей плановой проверки;
- `l2forge:servers-monitor` теперь пропускает проверку свежего снимка, а флаг `--force` оставлен для установки, обновления и явного ручного запуска.

## 0.13.24 - 2026-07-16

### Изменено

- мониторинг LoginServer, GameServer и онлайна больше не требует обязательной настройки cron или Планировщика Windows: публичная главная и административный дашборд автоматически запускают обновление устаревшего снимка через CMS;
- первый посетитель после длительного простоя видит нейтральный статус «Статус уточняется», после чего карточки обновляются без перезагрузки страницы; старое красное состояние не выдаётся за актуальное;
- одновременные посетители защищены атомарной блокировкой: только один запрос выполняет сетевые и SQL-проверки, остальные используют сохранённый результат и при необходимости кратко повторяют чтение;
- автоматический опрос выполняется не чаще одного раза в минуту; период и время блокировки доступны через `SERVER_MONITOR_REFRESH_INTERVAL_SECONDS` и `SERVER_MONITOR_LOCK_SECONDS`;
- Laravel Scheduler сохранён как необязательный режим постоянных проверок при отсутствии посетителей; он по-прежнему нужен для автоматической очистки журналов;
- добавлены JSON-endpoint мониторинга, клиентское обновление публичных карточек и административного дашборда, а также регрессионные тесты устаревшего, свежего и автоматически обновлённого снимка.

## 0.13.23 - 2026-07-16

### Добавлено

- фоновый мониторинг LoginServer и GameServer командой `l2forge:servers-monitor`, зарегистрированной в Laravel Scheduler с периодом одна минута;
- проверка фактической доступности процессов по TCP-портам служб, а не по состоянию подключения к базе данных;
- подсчёт онлайна для драйвера `l2j_mobius_ct0_interlude` по `characters.online = 1` с сохранением результата в базе CMS;
- компактный административный дашборд с общим онлайном, независимым состоянием каждого процесса GameServer и LoginServer, временем обновления и ручной кнопкой проверки;
- дополнительные необязательные адреса служб и порты в настройках подключений; по умолчанию используются адрес базы, порт `2106` для LoginServer и `7777` для GameServer;
- регрессионные тесты зелёного статуса, порога из трёх ошибок, подсчёта онлайна и публичного отображения.

### Изменено

- публичная главная показывает реальный статус и онлайн отдельно для каждого игрового мира; зелёный публичный статус требует доступности конкретного GameServer и связанного LoginServer;
- фиктивный лимит `5000 игроков` и шкала заполнения удалены;
- публичные статусные карточки и дашборд читают сохранённый снимок и не выполняют проверку портов или подсчёт онлайна при каждом открытии;
- первая и вторая неудачные проверки дают серый статус «Статус уточняется», красный статус появляется после трёх последовательных ошибок, а успешная проверка сразу возвращает зелёный статус.

## 0.13.22 - 2026-07-16

### Исправлено

- после удаления последнего GameServer карточки связанных игровых аккаунтов больше не отображаются игрокам как пустые записи «Сервер —»; сама связь с LoginServer сохраняется в базе CMS и не удаляет реальный игровой аккаунт;
- при удалении одного из нескольких GameServer связанные карточки автоматически переводятся на оставшийся мир того же LoginServer;
- при подключении нового GameServer к LoginServer ранее скрытые связи автоматически восстанавливаются;
- прямой переход и смена пароля для аккаунта без доступного GameServer возвращают 404 вместо показа устаревшей страницы;
- добавлены регрессионные тесты скрытия, переназначения и восстановления карточек игровых аккаунтов.

## 0.13.21 - 2026-07-16

### Исправлено

- кнопка подтверждения удаления LoginServer и GameServer снова выполняет удаление: Livewire-действие переименовано с конфликтующего `delete` на однозначное `deleteServer`;
- кнопка удаления блокируется на время запроса, предотвращая повторную отправку;
- добавлены регрессионные тесты фактического удаления обоих типов серверов через Livewire и проверки корректного действия в HTML.

## 0.13.20 - 2026-07-16

### Исправлено

- сигнатура метода страницы игрового аккаунта приведена к стилю Laravel Pint; проверка `pint --test` больше не останавливается на `GameAccountController`;
- поведение автоматического открытия единственного игрового аккаунта не изменено.

## 0.13.19 - 2026-07-16

### Изменено

- при наличии ровно одного игрового аккаунта вход в личный кабинет сразу открывает его страницу с персонажами и сменой пароля вместо промежуточной карточки;
- при отсутствии игровых аккаунтов сохраняется экран создания первого аккаунта, а при двух и более аккаунтах сохраняется общий список карточек;
- локализованные адреса личного кабинета перенаправляют на локализованную страницу единственного игрового аккаунта;
- на странице единственного аккаунта скрыта бессмысленная ссылка возврата к списку, а при разрешённом лимите остаётся кнопка создания дополнительного аккаунта;
- добавлены регрессионные тесты для нулевого, одного и нескольких игровых аккаунтов, локализованного перехода и доступности создания второго аккаунта.

## 0.13.18 - 2026-07-16

### Добавлено

- зарегистрирован отдельный LoginServer-драйвер `l2j_mobius_legacy` с названием `L2J Mobius Legacy — C1/C4`;
- Legacy-драйвер проверяет только таблицу `accounts` и не требует `account_data`, `accounts_ipauth` или `gameservers`;
- общий шлюз игровых аккаунтов поддерживает оба Mobius LoginServer-драйвера и использует для них существующий кодировщик пароля Base64(SHA-1);
- добавлены регрессионные тесты регистрации драйверов, Legacy-контракта таблицы `accounts`, списка в административной панели и поддержки шлюзом игровых аккаунтов.

### Изменено

- существующий драйвер `l2j_mobius` отображается как `L2J Mobius — Interlude и новее`; его идентификатор сохранён без изменений для совместимости с уже настроенными подключениями;
- документация по подключениям разделяет Legacy C1/C4 и современную схему Mobius.

## 0.13.17 - 2026-07-16

### Исправлено

- боковые панели LoginServer и GameServer теперь закрываются при нажатии на затемнённую область, а не при последующем отпускании кнопки мыши; выделение текста, начатое внутри панели и завершённое за её границей, больше не закрывает настройки;
- после неуспешной проверки подключения зелёный статус серверной карточки сразу меняется на серый «Не настроено», а успешная повторная проверка возвращает статус «Настроено»;
- буква в круглом аватаре меню администратора выровнена по вертикали;
- ошибки полей создания игрового аккаунта и смены игрового пароля оформлены как небольшие плавающие сообщения и больше не изменяют высоту строк формы и положение соседних полей;
- добавлены регрессионные проверки поведения серверных карточек, закрытия боковой панели, выравнивания аватара и плавающих ошибок формы.

## 0.13.16 - 2026-07-16

### Исправлено

- тест миграции старого bcrypt-хеша на Argon2id теперь записывает подготовленный legacy-хеш напрямую в базу, не пропуская его через Eloquent-преобразование `hashed`;
- чистая установка больше не останавливается на ложной ошибке `Could not verify the hashed value's configuration`, при этом рабочая логика проверки и автоматического перехеширования паролей не изменена;
- в тест добавлена явная проверка, что до входа в базе действительно находится bcrypt-хеш, а после успешного входа он заменяется Argon2id-хешем.

## 0.13.15 - 2026-07-16

### Исправлено

- класс `App\Support\PasswordHashing`, необходимый странице системной информации, повторно включён в патч и теперь проверяется установочным скриптом до запуска обновления;
- отображение типа хеша и тесты используют единые методы `PasswordHashing::label()` и `PasswordHashing::argon2idSupported()`;
- `update.ps1` больше не запускает Laravel со старым автозагрузчиком до `composer install`: Composer сначала пересобирает автозагрузку, затем очищается кэш Artisan;
- патч выдаёт понятную ошибку, если один из обязательных файлов распакован не полностью.

## 0.13.14 - 2026-07-16

### Изменено

- в разделе «Системная информация» добавлен фактически используемый тип хеша паролей: `Argon2id`, `Argon2i` или `bcrypt`;
- при резервном использовании bcrypt на системе без Argon2id под значением выводится нейтральная поясняющая строка без оценочных статусов;
- тип хеша добавлен в безопасный отчёт для технической поддержки;
- ошибки создания игрового аккаунта и смены игрового пароля теперь отображаются непосредственно под соответствующими полями;
- поля с ошибками получают заметную рамку и корректные связи `aria-describedby`, а общий блок ошибок на этих формах не дублирует те же сообщения;
- несовпадение повторного игрового пароля теперь относится к полю подтверждения, а не к основному полю пароля;
- добавлены регрессионные проверки системной информации и встроенных ошибок личного кабинета.

## 0.13.13 - 2026-07-15

### Исправлено

- режим `HASH_DRIVER=auto` снова предпочитает Argon2id и использует bcrypt только на PHP-сборках, где `password_algos()` не содержит `argon2id`;
- установщик и обновление больше не переписывают явно выбранный Argon2/Argon2id на bcrypt: неподдерживаемый явный драйвер останавливает запуск с диагностикой PHP;
- чистые установки сохраняют строгую проверку алгоритма (`HASH_VERIFY=true`); обновление с bcrypt может временно включить совместимый режим, после успешного входа пароль автоматически перехешируется текущим драйвером;
- для bcrypt добавлена проверка предела 72 байта при регистрации, сбросе пароля и создании или смене пароля администратора;
- удалены регистрозависимые дубликаты ключей `Login Server`/`Login server`, из-за которых Windows PowerShell считал встроенные `ru.json` и `en.json` некорректными;
- проверка сохранённого LoginServer или GameServer больше не открывает боковую панель: результат отображается зелёной или красной строкой непосредственно в карточке;
- добавлены регрессионные тесты автоматического выбора Argon2id, совместимости старых bcrypt-хэшей, ограничения bcrypt, языковых JSON и проверки серверных карточек.

## 0.13.12 - 2026-07-15

### Исправлено

- исправлен `setup.ps1`: создание SQLite-файла использует поддерживаемый параметр `New-Item -Path` вместо несуществующего `-LiteralPath`;
- `composer.lock` синхронизирован с `composer.json`, поэтому `composer validate` больше не останавливает чистую установку;
- стандартный алгоритм хеширования изменён на переносимый `bcrypt`, совместимый с PHP-сборками Windows без Argon2;
- `setup.ps1` и `update.ps1` проверяют `password_algos()` и автоматически заменяют неподдерживаемый `argon`/`argon2id` на `bcrypt`;
- `doctor.ps1` показывает отдельный результат проверки выбранного алгоритма хеширования;
- тестовое окружение использует `bcrypt` с четырьмя раундами, поэтому полный набор тестов не зависит от наличия Argon2 в системной сборке PHP;
- добавлена регрессионная проверка создания и проверки пароля в тестовом окружении.

## 0.13.11 - 2026-07-15

### Изменено

- экраны «Игровые серверы» и «Логин серверы» заменены компактными карточками, чтобы все подключения были видны без прокрутки длинных форм;
- создание и редактирование серверов перенесены в боковую панель с разделами основных параметров и подключения к базе;
- проверка подключения выполняется реактивно через Livewire 4.2 без перезагрузки страницы, изменения адреса и автоматической прокрутки;
- результат проверки показывается внутри панели, а подробный список проверенных таблиц раскрывается только по запросу;
- удаление перенесено в компактное меню действий с отдельным подтверждением;
- сохранены старые POST-маршруты серверных настроек для обратной совместимости, новый интерфейс использует Livewire-компоненты;
- русское название приведено к виду «Логин серверы», английское — к «Login Servers»;
- добавлены Livewire-тесты проверки подключений без сохранения введённых параметров;
- добавлена зависимость `livewire/livewire` версии `4.2.0`, включён CSP-safe frontend bundle и ограничения размера реактивных запросов.

## 0.13.10 - 2026-07-15

### Изменено

- маркер основного языка в кнопках перевода оформлен отдельным компактным бейджем и больше не склеивается с названием языка;
- стандартный лимит игровых аккаунтов на пользователя CMS снижен с 10 до 1, при этом сохранённые администратором значения не перезаписываются;
- из политики игрового логина удалены требования строчной и заглавной буквы, включая применение старых скрытых настроек; минимальная длина остаётся 4, требование цифры по умолчанию выключено;
- стандартная минимальная длина игрового пароля снижена до 6, требования строчной, заглавной буквы и цифры по умолчанию выключены;
- удалена не выполнявшая полезной операции кнопка «Проверить без удаления» и связанный маршрут;
- внизу страницы безопасности добавлен журнал последних попыток входа администратора с введённым email, найденным администратором, результатом, причиной, IP и User-Agent; пароли и коды 2FA не отображаются и не записываются;
- после проверки подключения LoginServer или GameServer страница возвращается к конкретной форме и автоматически показывает блок результата;
- исправлено центрирование буквы в аватаре администратора в верхнем меню;
- обновлены тесты политик игровых аккаунтов, журнала входов, якорей проверки подключений и административных шаблонов.

## 0.13.9 - 2026-07-15

### Изменено

- группы «Контент», «Сайт», «Серверы», «Пользователи» и «Система» в боковом меню админ-панели стали сворачиваемыми;
- по умолчанию закрыты все неактивные группы, а раздел текущей страницы раскрывается автоматически;
- выбранное администратором состояние неактивных групп сохраняется локально в браузере и восстанавливается при переходах;
- мобильное горизонтальное меню продолжает показывать все доступные ссылки без дополнительных действий;
- добавлены стили раскрытия, индикатор состояния и регрессионные проверки структуры меню.

## 0.13.8 - 2026-07-15

### Изменено

- административная панель больше не собирает разнородные функции в одном пункте «Настройки» с девятью глобальными вкладками;
- левое меню разделено на группы «Контент», «Сайт», «Серверы», «Пользователи» и «Система»;
- основные настройки, языки и темы собраны в группе «Сайт»;
- GameServer, LoginServer и политика игровых аккаунтов собраны в группе «Серверы»;
- список пользователей и параметры регистрации собраны в группе «Пользователи»;
- почта, безопасность, системная информация, администраторы, аудит и будущие модули собраны в группе «Система»;
- страницы получили самостоятельные заголовки вместо общего заголовка «Настройки»;
- внутренние вкладки почтовых шаблонов сохранены, так как относятся к одному почтовому модулю;
- старые URL и имена маршрутов `/admin/settings/*` сохранены для совместимости с закладками и обновлениями;
- удалены неиспользуемый шаблон глобальных вкладок и связанный с ним CSS;
- длинное боковое меню получило вертикальную прокрутку на невысоких экранах;
- добавлен регрессионный тест новой структуры бокового меню и отсутствия глобальной полосы вкладок.

## 0.13.7 - 2026-07-15

### Изменено

- стандартная тема обновлена до версии `0.7.1`: из шапки удалён статический пункт «Файлы», а из подвала — статическая ссылка «Скачать клиент»;
- блоки «Навигация» и «Документы» в подвале объединены в единый раздел «Разделы» со статическими ссылками и страницами, выбранными администратором;
- карточки игровых аккаунтов больше не показывают техническое имя LoginServer: вместо него выводятся все связанные GameServer с подписью «Сервер» или «Серверы»;
- подпись и ошибка подтверждения пароля уточнены до «пароль от личного кабинета»;
- для персонажей добавлена необязательная дата создания: драйвер Mobius читает `characters.createDate`, нормализует её в единое поле `created_at` и скрывает строку при отсутствии корректной даты;
- имя колонки даты хранится в реестре конкретного драйвера, поэтому будущие адаптеры смогут использовать собственную схему без изменения шаблонов кабинета;
- контракт схемы Mobius CT0 Interlude и документация подключений дополнены колонкой `createDate`;
- добавлены регрессионные тесты публичной навигации, списка нескольких GameServer, даты создания персонажа и текста подтверждения пароля.

## 0.13.6 - 2026-07-15

### Исправлено

- runtime-fallback страниц, новостей, игровых серверов, настроек сайта и почтовых шаблонов теперь использует только включённые локали: запрошенную, резервную и основную;
- отключённый русский язык больше не добавляется скрытым последним кандидатом и не может перехватить локализованный slug;
- добавлен регрессионный тест с немецким основным языком, английским резервным и отключённым русским пакетом;
- стандартная очередь приведена к фактической синхронной отправке: `QUEUE_CONNECTION=sync` используется в `.env.example`, `config/queue.php` и автоматически заменяет старое значение `database` при обновлении;
- `doctor.ps1` и `setup.ps1` проверяют `pdo_sqlite` либо `pdo_mysql` по фактическому `DB_CONNECTION`, а `pdo_mysql` дополнительно требуется для `GAME_ADAPTER=mobius`;
- SQLite-файл больше не считается обязательным и не создаётся при установке CMS на MySQL/MariaDB;
- диагностика предупреждает о небезопасных production-настройках `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_FORCE_HTTPS`, `SESSION_SECURE_COOKIE` и `LOG_LEVEL`;
- добавлена инструкция production-развёртывания с настройкой Linux cron, Windows Task Scheduler, сессий, кэша, резервных копий и текущего синхронного режима почты.

## 0.13.5 - 2026-07-15

### Исправлено

- устранены четыре ошибки Larastan/PHPStan без изменения поведения CMS;
- из обработчиков маршрутов игровых аккаунтов удалены недостижимые проверки `is_int()`: Laravel передаёт параметр маршрута как строку, объект либо `null`, а числовая строка по-прежнему строго проверяется через `ctype_digit()`;
- после успешного разбора версии MySQL группы регулярного выражения используются напрямую без лишнего `?? 0`;
- существующие проверки локализованных маршрутов игровых аккаунтов и таймаутов MySQL/MariaDB сохранены без ослабления.

## 0.13.4 - 2026-07-15

### Исправлено

- карточка игрового аккаунта и смена пароля больше не принимают локаль маршрута за идентификатор аккаунта на адресах вида `/ru/account/game-accounts/{id}`;
- идентификатор игрового аккаунта безопасно извлекается по имени параметра маршрута, поэтому одинаково работают локализованные и обычные адреса;
- добавлен регрессионный тест полного локализованного сценария: создание аккаунта, переход в карточку и смена игрового пароля;
- `GameAccountController`, `GameAccountSettings` и `ExternalGameAccountGateway` приведены к правилам Laravel Pint, включая импорты строгих типов, разделение элементов класса и PHPDoc.

## 0.13.3 - 2026-07-15

### Исправлено

- helper тестов личного кабинета приведён к формату Laravel Pint: создание пользователя, подтверждение email и возврат модели разделены пустыми строками;
- функциональная логика личного кабинета и production-код не изменялись.

## 0.13.2 - 2026-07-15

### Исправлено

- тестовые пользователи личного кабинета теперь подтверждают email через штатный `markEmailAsVerified()`, а не передают защищённое поле `email_verified_at` в массовое заполнение модели;
- устранены 14 ложных падений тестов ЛК, вызванных корректным перенаправлением фактически неподтверждённых тестовых пользователей на страницу подтверждения email;
- `email_verified_at` намеренно не добавлен в `$fillable`, поэтому защита модели от массового подтверждения email сохранена.

## 0.13.1 - 2026-07-15

### Совместимость игровых паролей

- пароли Mobius ограничены латинскими буквами и цифрами, чтобы исключить несовместимость кириллицы, пробелов и специальных символов с игровым клиентом;
- одинаковое ограничение применяется при создании аккаунта и смене игрового пароля;
- добавлены регрессионные тесты для кириллицы, специальных символов и пробелов.

### Защита внешней базы

- для запросов к MySQL 5.7+/8 применяется `max_execution_time`, а для MariaDB — `max_statement_time`;
- таймаут выполнения внешнего запроса по умолчанию составляет 3000 мс и задаётся через `EXTERNAL_DB_QUERY_TIMEOUT_MS`;
- запросы аккаунтов и персонажей используют прямое сравнение по индексированным полям без `LOWER(column)`, чтобы MySQL мог использовать индексы;
- количество персонажей в ответе ограничено безопасным пределом, по умолчанию 50;
- существующая мягкая деградация карточки аккаунта сохраняется: при ошибке или таймауте конкретный мир помечается временно недоступным вместо HTTP 500.

### Целостность LoginServer

- удаление LoginServer выполняется в транзакции с `lockForUpdate()`;
- повторно проверяются связи с GameServer и игровыми аккаунтами непосредственно перед удалением;
- конфликт внешнего ключа обрабатывается как штатное сообщение «LoginServer используется», а не как необработанное исключение.

## 0.13.0 - 2026-07-15

### Личный кабинет игрока

- публичный профиль заменён отдельной адаптивной зоной `/account` с собственным минималистичным шаблоном;
- добавлены обзор, счётчик игровых аккаунтов, доступные миры и карточки аккаунтов;
- игрок выбирает GameServer, а CMS автоматически создаёт аккаунт на связанном LoginServer;
- связь пользователя CMS с игровым логином хранится в новой таблице `user_game_accounts` без изменения схемы Mobius;
- карточка аккаунта показывает персонажей по каждому GameServer: ник, уровень, профессию, клан и онлайн-статус;
- добавлена смена игрового пароля с обязательным подтверждением текущего пароля CMS.

### Настройки и безопасность

- добавлена вкладка «Настройки → Игровые аккаунты»;
- администратор управляет общим лимитом аккаунтов, длиной логина и пароля, требованиями к регистру и цифрам;
- логины ограничены латинскими буквами и цифрами, а уникальность проверяется без учёта регистра;
- игровые пароли не сохраняются в CMS и исключены из old input и аудита;
- создание ограничено тремя попытками за десять минут, смена пароля — пятью попытками в час;
- удаление LoginServer блокируется, пока к нему привязаны аккаунты игроков;
- в карточке пользователя администратор видит его связанные игровые аккаунты.

### Драйвер Mobius и тесты

- добавлены чтение и запись `accounts`, безопасная смена пароля и чтение `characters`/`clan_data` через временные подключения;
- реализовано Base64 SHA-1 кодирование пароля L2J Mobius и таблица названий профессий Interlude;
- RUSaCis остаётся заглушкой и не используется для создания аккаунтов игроками;
- добавлены тесты авторизации, лимитов, политики данных, изоляции владельцев, персонажей, смены пароля и кодирования Mobius.

## 0.12.3 - 2026-07-14

### Исправлено

- удалена лишняя пустая строка в конце `SettingsController`, из-за которой Laravel Pint сообщал о нарушениях `class_attributes_separation`, `unary_operator_spaces` и `braces_position`;
- функциональная логика подключений LoginServer и GameServer не изменялась.

## 0.12.2 - 2026-07-14

### Исправлено

- проверка подключения LoginServer и GameServer больше не вызывает отсутствующий метод `Config::forget()` в Laravel 13;
- временное внешнее подключение создаётся через `DB::connectUsing()` и гарантированно удаляется через `DB::purge()`;
- удалён неиспользуемый метод `SettingsController::placeholder()`, вызывавший ошибку PHPStan;
- добавлен регрессионный тест, подтверждающий, что ошибка подключения возвращается как отчёт и не превращается в HTTP 500 при очистке соединения.

## 0.12.1 - 2026-07-14

### Исправлено

- формат многострочных стрелочных функций в тестах подключений LoginServer и GameServer приведён к правилам Laravel Pint;
- заголовок README обновлён до актуальной версии CMS.

## 0.12.0 - 2026-07-14

### Подключения серверов

- добавлена отдельная сущность LoginServer с драйвером, хостом, портом, именем базы, пользователем, зашифрованным паролем и кодировкой;
- один LoginServer можно связать с несколькими игровыми серверами, а удаление используемого LoginServer блокируется;
- каждый GameServer получил собственный драйвер и выбор: использовать параметры базы LoginServer либо отдельное подключение Game DB;
- пароли внешних баз шифруются через `APP_KEY`, не возвращаются в HTML и скрываются в журнале действий;
- пустое поле пароля при редактировании сохраняет действующий секрет без повторного шифрования.

### Драйверы и диагностика

- добавлен рабочий драйвер `L2J Mobius` для LoginServer с проверкой `accounts`, `account_data` и `accounts_ipauth`;
- добавлен рабочий драйвер `L2J Mobius — CT0 Interlude` для GameServer с проверкой `characters`, `account_gsdata` и `account_premium`;
- добавлены заглушки LoginServer и GameServer `RUSaCis`: соединение проверяется, но контракт таблиц будет добавлен позже;
- проверка создаёт временное MySQL-подключение, получает версию сервера, проверяет обязательные и необязательные таблицы и ничего не изменяет во внешней базе;
- `setup.ps1`, `doctor.ps1` и системная информация теперь проверяют расширение `pdo_mysql`.

### Аудит и качество

- добавлены события создания, изменения, удаления и проверки LoginServer, а также изменения и проверки подключения GameServer;
- добавлены функциональные тесты авторизации, шифрования и сокрытия паролей, сохранения общего и отдельного подключения, проверки без сохранения, валидации и блокировки удаления;
- добавлен unit-тест реестра драйверов и регрессионные проверки интерфейса;
- добавлена документация `docs/SERVER_CONNECTIONS.md`.

## 0.11.15 - 2026-07-14

### Исправлено

- статусы 2FA в списке и карточке администратора теперь отображаются как «Включена» и «Отключена» без повторения названия колонки;
- исправлен последний функциональный тест управления администраторами, который ожидал согласованный текст статуса.

## 0.11.14 - 2026-07-14

### Исправлено

- модель администратора теперь сразу получает начальную `session_version = 1`, а не только после повторного чтения записи из базы;
- тестовая авторизация администратора обновляет модель перед записью версии сессии, поэтому `actingAs()` больше не создаёт ложную отозванную сессию;
- устранены два замечания PHPStan в сервисе 2FA: лишний `array_values()` и недостижимый `catch`;
- чтение хешей резервных кодов перенесено в безопасный метод модели с обработкой повреждённого зашифрованного значения.

## 0.11.13 - 2026-07-14

### Исправлено

- приватный вспомогательный метод middleware больше не называется `terminate()`, поэтому Laravel не пытается вызвать его как завершающий middleware-хук;
- исправлены замечания Laravel Pint в модели администратора и TOTP-сервисе.

## 0.11.12 - 2026-07-14

### Двухфакторная аутентификация администраторов

- добавлена персональная TOTP-2FA без глобального обязательного переключателя;
- раздел «Безопасность аккаунта» вынесен в меню текущего администратора;
- секрет создаётся локально, хранится в зашифрованном поле и не записывается в журналы;
- настройка подтверждается действующим шестизначным кодом до включения защиты, после чего остальные активные сессии администратора отзываются;
- создаются восемь одноразовых резервных кодов, которые хранятся только в виде хешей;
- резервные коды показываются один раз, их можно скопировать или скачать локально из браузера;
- вход с включённой 2FA завершается только после отдельной проверки TOTP или резервного кода;
- для второго этапа добавлены отдельные лимиты 5 попыток в минуту и 20 попыток в час;
- добавлены регенерация резервных кодов и безопасное отключение 2FA с текущим паролем и кодом;
- QR-код формируется локально в браузере без передачи секрета внешним сервисам.

### Управление администраторами

- в списке и карточке администратора показывается статус 2FA и дата подключения;
- другой администратор может только аварийно сбросить 2FA после ввода собственного пароля;
- сброс удаляет секрет и резервные коды, отзывает remember-токен и активные административные сессии;
- добавлена команда `l2forge:admin-2fa:disable` для восстановления доступа через консоль;
- смена пароля и отключение учётной записи теперь также повышают версию сессии и отзывают старые входы; административные сессии, созданные до обновления 0.11.12, потребуют повторного входа.

### Аудит и качество

- фиксируются включение, отключение, подтверждение, неудачные проверки, использование резервного кода, регенерация и аварийный сброс; поля TOTP и резервных кодов дополнительно считаются чувствительными и редактируются аудитом;
- добавлены RFC 6238 test vectors и функциональные тесты полного двухэтапного входа; `phpunit.xml` теперь запускает также каталог `tests/Unit`;
- добавлена миграция полей 2FA и версии административной сессии; `doctor.ps1` проверяет наличие непустого `APP_KEY`;
- зависимости Composer не изменялись.

## 0.11.11 - 2026-07-14

### Исправлено

- исправлен часовой тест IP-лимитера входа администратора;
- тест больше не задаёт минутный лимит выше часового, из-за чего сервис безопасности автоматически повышал фактический часовой порог;
- 30 попыток теперь распределяются по шести минутным окнам, а 31-я проверяется после сброса минутного окна;
- рабочая логика лимитов, журналирования и настроек безопасности не изменялась.

### Качество

- Laravel Pint и Larastan/PHPStan продолжают проходить в установленном проекте;
- новые миграции и зависимости Composer отсутствуют.

## 0.11.10 - 2026-07-14

### Исправлено

- исправлены два теста IP-лимитера входа администратора после появления безопасных минимальных диапазонов;
- минутный сценарий теперь использует допустимый минимум 5 запросов и ожидает блокировку шестого;
- часовой сценарий теперь использует допустимый минимум 30 запросов и ожидает блокировку тридцать первого;
- рабочая логика лимитов, журналирования и настроек безопасности не изменялась.

### Качество

- Laravel Pint и Larastan/PHPStan уже проходят в установленном проекте;
- исправление позволяет `composer quality` перейти к полному PHPUnit-набору без ложного падения тестов лимитера;
- новые миграции и зависимости Composer отсутствуют.

## 0.11.9 - 2026-07-14

### Настройки безопасности

- добавлена вкладка «Настройки → Безопасность»;
- лимиты входа администратора по IP и связке email + IP теперь можно изменять в безопасных диапазонах;
- полностью отключить защиту через панель нельзя;
- сроки хранения `audit_logs` и `admin_login_logs` настраиваются отдельно;
- значения из базы имеют приоритет над безопасными значениями `.env` по умолчанию.

### Журналы

- в панели показываются общее и устаревшее количество записей обоих журналов;
- добавлен предварительный расчёт очистки без удаления;
- добавлена ручная очистка только устаревших записей;
- для ручной очистки требуется текущий пароль администратора;
- полная очистка журналов и удаление свежих записей через панель недоступны;
- дата последней автоматической или ручной очистки сохраняется в настройках;
- команда `l2forge:logs-clean` использует сроки хранения из панели, если они не переопределены параметрами командной строки.

### Безопасность и совместимость

- изменение настроек и ручная очистка записываются в журнал действий;
- добавлены тесты безопасных диапазонов, фактического применения лимитов, предварительного расчёта и очистки;
- новые миграции и зависимости Composer отсутствуют;
- существующие журналы, пользователи, контент и настройки сохраняются.

## 0.11.8 - 2026-07-14

### Безопасность и устойчивость

- добавлен независимый лимит входа администратора по IP: 10 запросов в минуту и 100 запросов в час;
- сохранён отдельный лимит по связке email и IP для защиты конкретной учётной записи;
- запросы, остановленные лимитером, больше не создают записи `throttled` в базе;
- попытки с неизвестным email больше не дублируются в общем `audit_logs`;
- добавлен регрессионный тест обхода лимита постоянной сменой email.

### Журналы

- команда `l2forge:logs-clean` теперь очищает `audit_logs` и `admin_login_logs`;
- для журнала входов установлен отдельный срок хранения 30 дней;
- удаление выполняется порциями по 1000 записей;
- файловый Laravel-log переведён на ежедневную ротацию с хранением 14 дней по умолчанию.

### Совместимость

- новые миграции отсутствуют;
- `composer.json` и `composer.lock` не изменялись;
- существующие настройки, пользователи, контент и журналы сохраняются.

## 0.11.7 - 2026-07-14

### Исправлено

- устранены 57 замечаний Larastan/PHPStan уровня 5 без baseline и `ignoreErrors`;
- добавлены точные типы атрибутов и связей для новостей, страниц, переводов и игровых серверов;
- исправлено определение типа дат публикации и коллекций переводов;
- административные и публичные контроллеры больше не работают с неопределённым базовым `Model`;
- устранены лишние nullsafe-операторы, проверки заведомо существующих ключей и недостижимые условия;
- уточнены возвращаемые структуры helper-функций и типы локализованного контента;
- повторная отправка подтверждения email теперь явно работает с моделью пользователя текущего guard.

### Качество

- конфигурация PHPStan сохранена на уровне 5;
- baseline, `ignoreErrors` и исключения каталогов не добавлялись;
- функциональная логика, миграции и зависимости Composer не изменялись.

### Совместимость

- новые миграции отсутствуют;
- `composer.json` и `composer.lock` не изменялись;
- пользовательские данные, настройки и контент сохраняются без изменений.

## 0.11.6 - 2026-07-14

### Исправлено

- устранены два оставшихся нарушения Laravel Pint `class_attributes_separation` в тестах страниц и общих настроек;
- после подключения trait `RefreshDatabase` добавлена обязательная пустая строка перед свойством тестового класса;
- функциональная логика CMS и тестовые сценарии не изменялись.

### Совместимость

- новые миграции и зависимости Composer отсутствуют;
- пользовательские данные, настройки и контент сохраняются без изменений.

## 0.11.5 - 2026-07-14

### Качество и устойчивость

- код проекта приведён к единому стилю Laravel Pint без ослабления preset;
- добавлена команда `composer format` для автоматического форматирования;
- `composer analyse` запускается без интерактивного progress-вывода;
- Larastan/PHPStan остаётся на уровне 5 без baseline и массовых исключений;
- добавлен `quality.ps1` для последовательного запуска Composer validation, Pint, PHPStan и PHPUnit;
- добавлен GitHub Actions workflow `.github/workflows/quality.yml`;
- установка и обновление CMS по-прежнему запускают функциональные тесты, но не изменяют код через Pint;
- apply-скрипт удаляет устаревший `create-demo-content.php`, который мог сохраниться после старых overlay-обновлений.

### Форматирование

- нормализованы импорты и полностью квалифицированные имена классов;
- исправлены пустые тела конструкторов и тестового базового класса;
- нормализованы PHPDoc, пустые строки, константы классов и операторы управления;
- удалены пустые скобки у `new Class()` в соответствии с Laravel preset.

## 0.11.4 - 2026-07-14

### Безопасность

- Все запросы `app/Http/Requests/Admin` переведены на общий `AdminFormRequest`, который явно требует авторизованного администратора через guard `admin`.
- Добавлен регрессионный тест, проверяющий явный отказ гостю и успешную авторизацию администратора для каждого административного FormRequest.

### Локализация и SEO

- Страницы и новости больше не показывают резервный перевод под URL другой локали.
- Неверный локализованный slug перенаправляется кодом `301` на канонический адрес перевода текущего языка.
- При отсутствии перевода для выбранной локали выполняется редирект `302` на фактическую резервную локаль и её slug.
- Нелокализованные адреса страниц и многоязычных новостей перенаправляют на реальный локализованный URL.
- Публичные страницы и новости публикуют `canonical`, `hreflang` и `x-default` только для существующих и включённых переводов.
- Переключатель языка сохраняет текущую страницу или новость и подставляет slug выбранного перевода.

### Качество кода

- Подключены Larastan 3.10 и PHPStan 2.2 с начальным уровнем 5 для `app`, `routes` и `database`.
- В Composer добавлены команды `lint`, `analyse`, `test` и `quality`.
- `MobiusGameServerAdapter` и изменённые PHP-файлы приведены к общему стилю Laravel Pint.
- Добавлены регрессионные тесты канонических адресов, резервной локали и SEO-ссылок страниц и новостей.

### Совместимость

- Новые миграции отсутствуют; страницы, новости, переводы, изображения и настройки сохраняются.
- `composer.json` и `composer.lock` изменены только из-за новых dev-зависимостей статического анализа.

## 0.11.3 - 2026-07-14

### Исправлено

- Предпросмотр несохранённой страницы теперь использует переданные заголовок, HTML-содержимое и SEO-поля вместо резервного системного slug.
- Модель страницы принимает как Eloquent-коллекции переводов из базы, так и обычные Laravel-коллекции, используемые для временной модели предпросмотра.
- Регрессионный тест `administrator can preview unsaved page from create and edit forms` снова проверяет настоящий несохранённый заголовок и форматированный HTML для `POST` и `PUT`.

### Совместимость

- Новые миграции и зависимости Composer отсутствуют.
- Созданные страницы, переводы, изображения, настройки и темы сохраняются без изменений.

## 0.11.2 - 2026-07-14

### Исправлено

- Окно подтверждения удаления страницы приведено к общему оформлению административной панели: добавлены белая карточка, затемнённый фон, предупреждающий знак и корректная раскладка кнопок.
- Переключатель «Показывать в подвале» больше не наследует стили текстового поля, поэтому при включении не появляется полупрозрачный синий прямоугольник.
- Маршрут предпросмотра страниц принимает `POST` и `PUT`: предпросмотр теперь работает как из формы создания, так и из формы редактирования со скрытым `_method=PUT`.
- Добавлены регрессионные тесты оформления страницы и предпросмотра несохранённого содержимого обоими HTTP-методами.

### Совместимость

- Новые миграции и зависимости Composer отсутствуют.
- Созданные страницы, переводы, изображения и настройки сохраняются без изменений.

## 0.11.1 - 2026-07-14

### Исправлено

- Тест навигации многоязычных страниц теперь учитывает текущую локаль сессии после переключения на английский язык и отдельно проверяет английскую и русскую подписи.
- Тест загрузки изображения страницы больше не зависит от расширения PHP GD: используется готовый корректный PNG-файл, создаваемый без генерации изображения.
- Установка и обновление CMS больше не останавливаются на окружениях без GD, если само расширение не требуется для рабочего функционала.

### Совместимость

- Рабочая логика страниц, маршруты, база данных и Composer-зависимости не изменялись.
- Новые миграции отсутствуют; все страницы и загруженные изображения сохраняются.

## 0.11.0 - 2026-07-14

### Добавлено

- Новый раздел «Контент → Страницы» для создания правил, контактов, политики конфиденциальности и других информационных материалов.
- Динамические языковые вкладки для всех включённых языков без жёсткой привязки к RU/EN и без миграций при добавлении нового языкового пакета.
- Отдельные заголовки, адреса, HTML-содержимое, SEO-заголовки и SEO-описания для каждой локали.
- Черновики и публикация страниц, вывод в шапке и подвале сайта, а также настраиваемый порядок навигации.
- Безопасный визуальный редактор, загрузка изображений и предпросмотр страницы до сохранения.
- Публичные адреса `/pages/{slug}` и локализованные варианты `/{locale}/pages/{slug}`.
- Переключатель языка сохраняет текущую страницу и подставляет её адрес для выбранной локали.
- Таблицы `pages` и `page_translations`, модели, контроллеры, журналирование действий и набор автоматических тестов.
- Команда `l2forge:page-media-clean` для безопасной очистки старых изображений, которые не используются ни одной страницей.
- Документация `docs/PAGES.md` для владельцев сайтов и авторов тем.

### Безопасность и совместимость

- HTML страниц очищается независимо от новостей; разрешены только безопасные элементы, локальные загруженные изображения и ограниченное форматирование.
- Неопубликованные страницы не открываются по прямой ссылке и не попадают в навигацию.
- Существующие новости, темы, настройки, пользователи, языки и почтовые шаблоны сохраняются.
- Зависимости Composer не изменялись. Обновление добавляет одну миграцию.

## 0.10.6 - 2026-07-14

### Исправлено

- `CustomHtmlMail` переведён с устаревшего метода `build()` на декларативные методы Laravel `envelope()` и `content()`.
- Тема произвольного HTML-письма теперь доступна `Mail::fake()` через `Envelope`, поэтому проверка `hasSubject()` больше не возвращает ложный результат.
- Готовый HTML передаётся через `Content::htmlString()`, без шаблонизации и изменения разрешённой разметки.
- Реальная SMTP-отправка и защита произвольных HTML-писем остаются без изменений.

### Совместимость

- Новые миграции и зависимости Composer отсутствуют.
- SMTP-настройки, почтовые шаблоны и существующие данные не изменяются.

## 0.10.5 - 2026-07-14

### Исправлено

- Произвольные HTML-письма теперь отправляются через отдельный `CustomHtmlMail`, поэтому `Mail::fake()` корректно перехватывает отправку и тесты больше не пытаются подключаться к реальному SMTP-серверу.
- Тест системных почтовых шаблонов явно задаёт название сайта `Eternal World` и больше не зависит от локального `.env`, кэша конфигурации или заводского значения `L2Forge CMS`.
- Тест произвольного HTML-письма дополнительно проверяет получателя, тему и сохранение разрешённого HTML-содержимого.

### Совместимость

- Новые миграции и зависимости Composer отсутствуют.
- SMTP-настройки, почтовые шаблоны и существующие данные не изменяются.

## 0.10.4 - 2026-07-14

### Исправлено

- Устранена ошибка проверки встроенных переводов в `doctor.ps1` и установщике: PowerShell больше не обнаруживает регистрозависимые пары ключей как дубликаты JSON.
- Исправлены все 19 конфликтов ключей в русской и английской локализациях, включая `Login Server` / `Login server`, `Logo` / `logo`, `Password` / `password` и аналогичные пары.
- Внутренние ключи подписей полей валидации, языковых маркеров и заголовков публичных форм сделаны уникальными без изменения отображаемого текста.
- Вкладка настроек логин-сервера использует единый ключ `Login Server`.

### Совместимость

- Новые миграции и зависимости Composer отсутствуют.
- Существующие настройки, SMTP-параметры, почтовые шаблоны, новости и пользовательские данные не изменяются.

## 0.10.3 - 2026-07-13

### Добавлено

- В каждом системном почтовом шаблоне появилось отдельное поле «Название в шапке письма». Оно изменяет надпись в тёмной шапке стандартного письма без изменения остального оформления.
- Значение шапки настраивается отдельно для каждого шаблона и языка; заводское значение — `{{site_name}}`.
- Новая вкладка «Почта → Отправить письмо» для отправки одного произвольного HTML-письма конкретному получателю.
- Готовый редактируемый HTML-пример, изолированный предпросмотр и поддержка таблиц, изображений, ссылок и inline CSS.
- Журналирование успешной и неудачной отправки произвольных писем без сохранения полного HTML-содержимого.

### Изменено

- Верхняя часть формы входа в админ-панель переразложена: знак `L2` находится слева, переключатель языков — справа, а название `L2Forge CMS` и подпись `CONTROL PANEL` расположены отдельным блоком ниже.
- Системные уведомления продолжают использовать стандартное оформление Laravel, но текст в тёмной шапке берётся из выбранного шаблона.

### Безопасность и совместимость

- Произвольные письма отправляются только по одному адресу и ограничены пятью отправками в минуту. Массовая рассылка не добавлена.
- Перед отправкой блокируются PHP, Blade, скрипты, формы, JavaScript-обработчики, небезопасные URL-схемы и CSS-выражения.
- Новые миграции и зависимости Composer отсутствуют. Существующие SMTP-настройки и тексты шаблонов сохраняются.

## 0.10.2 - 2026-07-13

### Исправлено

- Раздел тем больше не пытается вывести глобально переданный массив манифеста как строку: в счётчике используется отдельный скалярный идентификатор активной темы.
- Для события журнала `user.email_verified` восстановлена отдельная подпись «Подтверждён email», не меняющая текст статуса учётной записи.
- Английская форма входа использует согласованную подпись `Username or email`.
- Модель перевода новости принимает `news_id` при прямом создании записи, поэтому локализованные slug и содержимое корректно сохраняются в тестах и служебных сценариях.
- Аналогично разрешено прямое заполнение `game_server_id` в переводах игровых серверов.

### Совместимость

- Новые миграции и зависимости Composer отсутствуют.
- Существующие переводы, темы, новости, пользователи и настройки не изменяются.

## 0.10.1 - 2026-07-13

### Исправлено

- Устранён фатальный конфликт свойства `locale` в тестовом уведомлении почтового шаблона с одноимённым свойством базового класса Laravel `Notification`.
- Тестовая отправка сохранённого почтового шаблона снова выполняется без аварийного завершения PHP.
- Восстановлены утверждённые русские формулировки в настройках игрового сервера, регистрации, заглушке Login Server и разделе тем.
- Счётчик игровых серверов использует отдельный переводческий ключ, чтобы не смешивать формы «Игровые серверы» и «Игровых серверов».
- Автоматические тесты явно запускаются с русской локалью и английским резервным языком независимо от содержимого локального `.env`.
- Проверка сокрытия секретов в журнале учитывает стабильный, не зависящий от языка маркер `[REDACTED]`.

### Совместимость

- Новые миграции и зависимости Composer отсутствуют.
- Пользовательские языки, переводы контента, SMTP-настройки и почтовые шаблоны не изменяются.

## 0.10.0 - 2026-07-13

### Добавлено

- Полная русская и английская локализация административной панели и стандартной публичной темы.
- Раздел «Настройки → Языки» со списком установленных пакетов, включением языков, выбором языка по умолчанию и резервного языка.
- Явные публичные маршруты с кодом языка: `/ru/...`, `/en/...`; старые адреса без префикса сохранены для совместимости.
- Сохранение выбранного языка в сессии и в учётных записях пользователей и администраторов.
- Переводы названия, описания и подвала сайта, названий игровых серверов, новостей и почтовых шаблонов.
- Отдельные таблицы `news_translations` и `game_server_translations` с произвольным кодом локали длиной до 10 символов.
- Разные slug новостей для каждого языка.
- Русские и английские заводские шаблоны подтверждения email, восстановления пароля и уведомления о смене пароля.
- Автоматическое обнаружение дополнительных проверенных языковых пакетов из `lang/<locale>/language.php` и `lang/<locale>.json`.
- Проверка встроенных языковых файлов и синхронности ключей в `doctor.ps1`.
- Документация для администраторов, авторов тем, языковых пакетов и будущих модулей.

### Миграция и совместимость

- Существующие пользователи и администраторы получают язык `ru`.
- Существующие новости, названия серверов, настройки сайта и изменённые почтовые шаблоны переносятся в русский перевод.
- Старые поля и ключи сохраняются как совместимый источник значений языка по умолчанию.
- `.env`, `APP_KEY`, SMTP-пароль, загруженные изображения и пользовательские переводы обновлением не перезаписываются.

### Безопасность

- Добавление языка через загрузку ZIP в браузере не реализовано; файловые пакеты устанавливаются только владельцем сервера.
- Коды локалей проходят строгую нормализацию и не хранятся в `ENUM`, поэтому новый язык не требует миграции схемы.
- Неизвестный или отключённый языковой префикс возвращает 404.
- Подписанные ссылки подтверждения email формируются для языка пользователя и не раскрывают токены в журнале.

## 0.9.6 - 2026-07-13

### Исправлено

- `doctor.ps1` определяет корень проекта по собственному расположению и использует абсолютные пути для всех проверок каталогов.
- Проверка загрузочных каталогов больше не зависит от текущей папки PowerShell, например `C:\Users\1`.
- В сообщении о реально отсутствующем каталоге дополнительно выводится его абсолютный путь.

### Совместимость

- Миграции и зависимости Composer не изменялись.
- Исправление затрагивает только диагностический скрипт и документацию релиза.

## 0.9.5 - 2026-07-13

### Исправлено

- Исправлена ошибка компиляции Blade-шаблона на странице редактирования почтовых шаблонов.
- Переменные вида `{{site_name}}` теперь формируются без вложенных Blade-выражений, из-за которых страница возвращала HTTP 500.
- Вкладки подтверждения email, восстановления пароля и уведомления о смене пароля снова открываются корректно.

### Совместимость

- Миграции и зависимости Composer не изменялись.
- Пользовательские SMTP-настройки и тексты шаблонов не затрагиваются.

## 0.9.4 - 2026-07-13

### Добавлено

- Внутренние вкладки почтовых настроек: подключение, подтверждение email, восстановление пароля и уведомление о смене пароля.
- Готовые стандартные шаблоны писем, работающие сразу после установки.
- Безопасное редактирование темы, заголовка, основного текста, текста кнопки и дополнительного текста без написания HTML.
- Разрешённые переменные шаблонов с проверкой неизвестных значений.
- Предпросмотр письма и вставка переменных по нажатию.
- Тестовая отправка каждого сохранённого шаблона с демонстрационными данными.
- Восстановление стандартного шаблона без потери SMTP-настроек.
- Отдельное уведомление после успешной смены пароля.
- События журнала для изменения, сброса и тестирования шаблонов.

### Безопасность

- HTML-теги в редактируемых полях шаблонов блокируются.
- Ссылки кнопок подтверждения и восстановления создаются CMS и не редактируются администратором.
- Тестовые письма не создают настоящие токены.
- Полное содержимое писем и персональные ссылки не записываются в журнал.

### Совместимость

- Новая миграция не требуется: пользовательские шаблоны сохраняются в существующей таблице `cms_settings`.
- Зависимости Composer не изменялись.

## 0.9.3 - 2026-07-13

### Исправлено

- `doctor.ps1` больше не считает каталог недоступным для записи только из-за кратковременной ошибки удаления диагностического файла.
- Проверка каталогов загрузки выводит конкретный путь и текст ошибки, когда запись действительно невозможна.
- Вкладка «Система» использует ту же логику: успешная запись определяется отдельно от очистки временного файла.
- Очистка диагностических файлов выполняется в режиме best effort и не маскирует фактический результат проверки записи.

### Совместимость

- Миграции и зависимости Composer не изменялись.
- Исправление рассчитано на Windows и сохраняет совместимость с Linux.

## 0.9.2 - 2026-07-13

### Исправлено

- Активная новая учётная запись больше не распознаётся middleware как отключённая до повторной загрузки модели из базы.
- Проверка статуса пользователя блокирует доступ только при явном значении `is_active = false`.
- Тестовые пользователи корректно получают `email_verified_at`, поэтому поиск, фильтры и карточка пользователя проверяют реальное подтверждение email.
- Проверка подтверждения email по подписанной ссылке снова проходит вместе с middleware активной учётной записи.

### Тестирование

- Исправлены три ложных сбоя в `UserManagementTest` и `PublicUserAuthenticationTest`.
- Миграции и зависимости Composer не изменялись.

## 0.9.1 - 2026-07-13

### Добавлено

- Раздел «Пользователи» в административной панели по адресу `/admin/users`.
- Поиск пользователей CMS по логину и email.
- Фильтры по состоянию учётной записи и подтверждению email.
- Список с датой регистрации, датой последнего успешного входа и пагинацией по 50 записей.
- Подробная карточка пользователя с основными сведениями и последними связанными событиями журнала.
- Включение и отключение пользовательской учётной записи без физического удаления.
- Повторная отправка письма подтверждения email.
- Отправка стандартной ссылки восстановления пароля без доступа администратора к паролю пользователя.
- Автоматические тесты управления пользователями CMS.

### Изменено

- После успешного публичного входа и автоматического входа после регистрации сохраняется `last_login_at`.
- Отключённый пользователь не может войти, а существующая авторизованная сессия завершается при следующем защищённом запросе.
- Меню и главная страница админки содержат рабочую ссылку на раздел пользователей.
- Документация пользователей, панели, безопасности, журнала и дорожной карты обновлена.

### Безопасность

- Пароли, их хэши, токены подтверждения, токены восстановления и идентификаторы сессий не выводятся в панели.
- Отключение пользователя инвалидирует remember-токен и удаляет его серверные сессии из стандартной таблицы Laravel.
- Игровые аккаунты и персонажи не смешиваются с учётными записями CMS и остаются отдельным будущим этапом.

## 0.9.0 - 2026-07-13

### Добавлено

- Раздел «Администраторы» в панели управления.
- Список администраторов с датой создания, датой последнего входа и состоянием учётной записи.
- Создание дополнительных администраторов через веб-интерфейс.
- Изменение имени и email администратора.
- Смена собственного пароля с обязательным подтверждением текущего пароля.
- Установка нового пароля другому администратору.
- Включение и отключение административных учётных записей без физического удаления.
- Журналирование создания, изменения, смены пароля, включения и отключения администраторов.
- Документация раздела в `docs/ADMINISTRATORS.md`.
- Автоматические тесты управления административными учётными записями.

### Безопасность

- Нельзя отключить собственную учётную запись.
- Нельзя отключить последнего активного администратора.
- Сессия отключённого администратора завершается при следующем запросе к панели.
- Пароли не попадают в журнал действий.
- Все администраторы пока имеют одинаковые права; преждевременная система ролей не добавлялась.

## 0.8.4 - 2026-07-13

### Добавлено

- Вкладка «Система» в настройках администратора с версиями L2Forge CMS, PHP, Laravel и Composer.
- Сведения об операционной системе, PHP SAPI, архитектуре, окружении Laravel, драйверах кэша, сессий, очередей, почты и логов.
- Информация о подключении и версии базы CMS, относительном пути и размере SQLite-файла.
- Проверка базы данных, почты и реальной возможности записи в служебные каталоги.
- Список обязательных и дополнительных расширений PHP.
- Безопасный отчёт для поддержки с кнопкой копирования без паролей, ключей, токенов и абсолютных путей.
- Документация системной вкладки в `docs/SYSTEM.md`.

### Изменено

- Единственным источником версии установленной CMS стал корневой файл `VERSION`.
- Подвал админки, версия ресурсов, совместимость тем и PowerShell-скрипты используют номер из `VERSION`.
- `setup.ps1`, `update.ps1` и `doctor.ps1` проверяют наличие и формат файла версии.

## 0.8.3 - 2026-07-13

### Исправлено

- Команда очистки изображений новостей больше не вызывает ошибку `SplFileInfo::getMTime(): stat failed` на Windows, если файл исчез между получением списка и обработкой.
- Публичная авторизация теперь всегда использует guard `web` и не зависит от guard, который был активен ранее в том же сеансе или тесте.
- Существующие автоматические тесты журнала действий и очистки изображений теперь проходят после указанных исправлений.

### Установка

- В релиз добавлен `composer.lock`: чистая установка использует зафиксированные версии пакетов вместо разрешения последних доступных зависимостей.
- В `composer.json` зафиксирована расчётная платформа PHP 8.3.0, поэтому lock-файл остаётся совместимым с заявленным минимумом PHP 8.3 даже при сборке релиза на PHP 8.5.
- `setup.ps1` и `update.ps1` останавливаются с понятной ошибкой, если `composer.lock` отсутствует, и не устанавливают непредсказуемые версии зависимостей.
- Для первого скачивания пакетов по-прежнему требуется доступ к интернету либо заполненный локальный кэш Composer.

## 0.8.2 - 2026-07-13

### Добавлено

- Раздел «Журнал действий» в административной панели с категориями, пагинацией и подробным просмотром записи.
- Универсальная таблица `audit_logs` и сервис `AuditLogger` для ядра, адаптеров и будущих модулей.
- События входа и выхода администраторов и пользователей, управления новостями, настройками, игровыми серверами и темами.
- События регистрации, подтверждения email, смены пароля и отправки почтовых уведомлений.
- Фиксация результата, инициатора, объекта, IP-адреса, User-Agent и безопасных дополнительных данных.
- Команда `l2forge:logs-clean` с режимом `--dry-run`, настраиваемым сроком хранения и ежедневным заданием Laravel Scheduler.
- Документация журнала для администраторов и разработчиков модулей.
- Автоматические тесты интерфейса, событий, очистки и маскировки секретных данных.

### Безопасность

- Пароли, токены, cookies, ключи и другие секретные поля рекурсивно заменяются отметкой `[СКРЫТО]`.
- Сбой записи журнала не прерывает основную операцию CMS и попадает только в технический Laravel-log.
- Записи журнала доступны только администраторам и не редактируются через веб-интерфейс.
- При удалении исходного объекта сохраняются снимки его имени и идентификатора, без хранения секретного содержимого.

## 0.8.1 - 2026-07-13

### Добавлено

- В настройках регистрации отображаются требования к логину и паролю.
- Добавлен базовый русский файл сообщений валидации для публичных форм.
- Добавлены тесты правил регистрации и русских сообщений проверки пароля.

### Исправлено

- Сообщения о необходимости буквы, цифры и минимальной длине пароля теперь выводятся на русском языке.
- Те же русские сообщения применяются при установке нового пароля через восстановление доступа.

## 0.8.0 - 2026-07-13

### Added

- Separate public website user accounts, independent from CMS administrators and future Lineage II game accounts.
- Administrator settings tabs for registration and SMTP mail.
- Registration enable/disable switch and optional mandatory email verification.
- SMTP host, port, encryption, username, encrypted password, sender identity and notification email settings.
- Test-email action with a persisted successful verification state.
- Public registration, login, logout and minimal account pages.
- Signed email-verification links with resend support.
- Password-reset request and reset flows using one-time database tokens.
- Custom Russian verification and password-reset notifications.
- Rate limits for login, registration, verification email resend and password recovery.
- Automated coverage for registration settings, encrypted SMTP storage, user registration, login, verification and password recovery.

### Security

- SMTP passwords are encrypted with the application `APP_KEY`, never rendered back into forms and excluded from application logs.
- Registration with mandatory email verification cannot be enabled until a test email succeeds.
- Changing SMTP settings invalidates the previous successful mail test.
- Public registration is unavailable while required email delivery is not ready.
- User logins and emails are normalized to lowercase; login receives a database unique index.
- Passwords use the configured Argon2id hashing driver.
- Password recovery returns the same public response for known and unknown email addresses.

## 0.7.2 - 2026-07-12

### Added

- Database-backed list of game servers with create, edit and delete actions in the administrator settings.
- Automatic migration of existing 0.7.1 `server.*` settings into the first game-server record.
- Public rendering of multiple game servers in the default theme and on the basic About page.
- Confirmation dialog before deleting a game server.
- Automated coverage for optional fields, multiple servers, deletion and legacy migration.

### Changed

- Server rates and chronicles are now optional and disappear from the public theme when empty.
- The public label `Версия` was renamed to `Хроники`.
- Future database connection placeholders are grouped separately for each saved game server.

### Fixed

- The default hero background is aligned to the top so the character head is no longer cropped behind the upper page area.

## 0.7.1 - 2026-07-12

### Added

- Working **Game Server** settings tab at `/admin/settings/game-server`.
- Display settings for server name, rates, chronicle and mode.
- Public-theme integration for the hero block, server status panel and the basic information page.
- Special `None` or empty mode value that hides the Mode item from the public server panel.
- Prepared disabled fields for the future game-database host, port, database name, user and password.
- Automated coverage for access control, validation, public rendering, hidden mode and protection against storing placeholder connection values.

### Security

- Database connection placeholders are disabled, have no submitted names and are not stored in `cms_settings`.
- Game-server display values are validated and escaped by Blade before public rendering.

### Fixed

- `doctor.ps1` now checks the real writable leaf directories used for news covers, news content, logos and favicons instead of only their parent folders.

## 0.7.0 - 2026-07-12

### Added

- Administrator settings section at `/admin/settings` with top-level tabs for General, Game Server and Login Server.
- General settings for site name, short description, logo, favicon, timezone, administrator email and footer text.
- Default footer text: `© 2026 L2Forge-CMS`.
- Secure logo and favicon uploads with random filenames, strict format checks and automatic cleanup when images are replaced or removed.
- Public-theme integration for page titles, meta description, logo, favicon, hero description and footer text.
- Runtime application of the configured timezone with `.env` as the safe fallback.
- Environment diagnostics for the settings upload directory.
- Automated feature coverage for settings access, saving, uploads, replacement, removal, unsafe SVG rejection and placeholder tabs.

### Security

- SVG files are rejected for both logo and favicon uploads.
- Site images are stored only inside `public/uploads/settings` and database values are normalized before use or deletion.
- Database, SMTP and application secrets remain outside the administrator settings and continue to live in `.env`.

## 0.6.2 - 2026-07-12

### Changed

- Moved the red news deletion action from the editor to each item in the administrator news list.
- Replaced the `На сайте` action with `Удалить` so the primary list actions are grouped together.
- Kept the existing confirmation dialog and safe media cleanup behavior.

## 0.6.1 - 2026-07-12

### Added

- Permanent news deletion with a red confirmation action in the editor.
- Administrator news pagination with 10 items per page.
- Unsaved news preview rendered through the active public theme in a separate tab.
- Automatic removal of inline images removed while editing when no other news item references them.
- Cleanup of abandoned cover images in addition to inline images.
- Automated coverage for deletion, shared-image protection, preview, pagination, media cleanup and 0.5.0 plain-text migration.

### Security

- Preview routes require administrator authentication, CSRF validation and rate limiting.
- Preview responses are private, non-cacheable and excluded from indexing.
- Newly selected preview covers are embedded only in the one-time response and are not saved to disk.
- Media deletion accepts only validated paths inside `public/uploads/news` and rechecks database references before deleting files.

## 0.6.0 - 2026-07-12

### Added

- Visual news editor with headings, bold, italic, underline, strike-through, lists, quotes, links, alignment, text colors and separators.
- Cover image upload with preview, replacement and removal.
- Authenticated inline image upload for news content.
- Cover images on the home page, public news list and full news page.
- Responsive rich-content styling in the default theme.
- Migration converting existing 0.5.0 plain-text news bodies to safe HTML paragraphs.
- `l2forge:news-media-clean` command for safely removing old unreferenced inline images.

### Security

- Server-side allow-list HTML sanitizer based on DOM parsing.
- Scripts, styles, iframes, forms, SVG, event handlers and unknown attributes are removed.
- Inline image sources are restricted to files uploaded through L2Forge CMS.
- Uploads accept only validated JPEG, PNG and WebP images up to 5 MB and 6000×6000 pixels.
- Random UUID filenames prevent trusting original client filenames.
- Inline image uploads are authenticated, CSRF-protected and rate-limited.

## 0.5.0 - 2026-07-12

### Added

- News management section at `/admin/news`.
- Empty state with a direct action for creating the first news item.
- News creation and editing forms.
- Draft, scheduled and published states.
- Automatic unique slug generation with stable URLs after title edits.
- Publication counters and links to live news pages.
- Feature tests for administrator news management and public visibility.
- Clean installations now start without demo news, so the administrator sees the first-news empty state.

### Security

- News body is rendered as escaped plain text with preserved line breaks.
- Draft and scheduled news cannot be opened through public routes.
- All administrator write operations remain protected by authentication and CSRF.

## 0.4.0 - 2026-07-12

### Changed

- Project renamed from L2CMS Core to **L2Forge CMS**.
- Default application name changed to `L2Forge CMS`.
- Composer package renamed to `l2forge/cms`.
- Administrator creation command renamed to `php artisan l2forge:admin-create`.
- Installer, diagnostics, documentation, default theme metadata and control panel branding updated.
- Existing `.env` files using the old default `APP_NAME` are migrated automatically by `setup.ps1` and `update.ps1`.

## 0.3.2 - 2026-07-12

### Fixed

- Moved administrator static assets from `public/admin` to `public/assets/admin`.
- Fixed the physical directory collision that caused PHP's development server to return its own 404 page for `/admin`.
- Added an environment diagnostic check preventing the reserved `public/admin` path from returning.

## 0.3.1 - 2026-07-12

### Changed

- `/admin` is now the single entry point for the administration panel.
- `/admin/dashboard` redirects to `/admin` for compatibility.
- The administration interface now uses a simpler, neutral and minimal built-in design.
- All implemented and planned sections remain visible in the left navigation.
- The administration home page now presents available and planned sections without optional database statistics.
- The theme management page was simplified to a compact list.

### Fixed

- Removed unnecessary dashboard queries so the main `/admin` page is less likely to fail when optional data is unavailable.
- Added feature tests for the main administration route and compatibility redirect.

## 0.3.0 - 2026-07-12

### Added

- Unified administrator layout independent from public themes.
- Persistent administrator navigation with responsive behavior.
- Theme management page at `/admin/themes`.
- Database-backed CMS settings storage for the active theme.
- Theme manifest, required-file, slug, preview-path and CMS-version validation.
- Safe activation of preinstalled themes with CSRF and administrator authentication.
- Theme activation logging through the application log.
- Automated feature tests for theme management.

### Security

- Public themes cannot replace administrator templates or administrator assets.
- Theme activation accepts only validated slugs inside the configured themes directory.
- Invalid, damaged, missing, or incompatible themes cannot be activated.
- Theme ZIP upload remains disabled until secure archive extraction is implemented.

## 0.2.0 - 2026-07-12

### Added

- Separate administrator model, database table, session guard and protected `/admin` area.
- Administrator login and logout with session regeneration and invalidation.
- `php artisan l2cms:admin-create` command for secure creation of the first administrator.
- Rate limiting by normalized email and IP address.
- Security log for successful, failed, inactive and throttled login attempts.
- Argon2id as the default CMS password hashing driver.
- Security headers and `noindex` metadata for the administration area.
- Initial administration dashboard and responsive administration design.
- Automated feature tests for administrator authentication.

### Security

- No default administrator account or password is included in seed data.
- Authentication errors do not reveal whether an administrator email exists.
- Admin pages are non-cacheable and protected from framing.

## 0.1.1 - 2026-07-12

- Fixed clean Windows installation, PowerShell encoding, required directories and Laravel 13 dependencies.
