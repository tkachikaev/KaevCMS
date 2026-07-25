# Administrative panel

The administrative interface is part of KaevCMS Core and is never rendered through a public theme.

## Administrator path

The default panel address is `/admin`. The administrator may change only the suffix after the fixed `admin-` prefix, for example:

```text
/admin-test01
/admin-control-2407
```

An empty suffix restores `/admin`. After a change, the previous address returns `404`, named routes immediately generate the new address, and the browser redirects the current administrator to it. The change form displays the current and new addresses and requires an explicit confirmation.

The path is stored in `cms_settings` under `admin.path_suffix`. It is not a replacement for a strong password, rate limits or TOTP-2FA; it only removes the predictable public entry point and reduces automated noise.

Recovery commands:

```bash
php artisan kaevcms:admin-path
php artisan kaevcms:admin-path test01
php artisan kaevcms:admin-path --reset
```

The first command shows the current address, the second sets a new suffix, and the third resets the panel to `/admin`. These commands are also shown in the information tooltip next to the setting.

All examples below use the default `/admin` prefix. A configured suffix replaces it everywhere.

- `/admin` — the main administration page and the single panel entry point.
- `/admin/news` — news management inside the same administration shell.
- `/admin/themes` — theme management inside the same administration shell.
- `/admin/settings` — main system and public website settings; related sections remain on compatible `/admin/settings/*` routes.
- `/admin/settings/admin-panel` — administrator path and server monitoring settings.
- `/admin/settings/system` — read-only system diagnostics and safe support report.
- `/admin/users` — CMS user management and account details.
- `/admin/administrators` — administrator account management.
- `/admin/logs` — human-readable audit log with categories and event details.
- `/admin/login` — administrator authentication.

## Navigation

The left menu always shows both implemented and planned sections. Planned entries are disabled and have no writable route until their functionality is implemented.

Current navigation structure:

- **Content** — news and pages.
- **Appearance** — public themes and player-account themes.
- **Servers** — GameServer worlds and LoginServer connections.
- **Users** — CMS users and administrators.
- **Mail** — direct access to SMTP, delivery and mail-template settings.
- **Settings** — one sidebar entry for site, administrator panel, registration, game-account policy, languages, security and system information.
- **Audit log** — administrator and system activity.
- **Modules** — discovered extensions, compatibility, migration state, installation, updates and activation; administrators have read-only access, while owners perform changes.

Settings use a local tab bar so closely related pages stay together without crowding the sidebar. The **Administrator panel** tab contains the configurable panel path and server-monitoring interval. **System information** is read-only diagnostics. Legacy update endpoints under `/admin/settings/system/*` and `/admin/dashboard` were removed in 0.23.10. Current forms and integrations must use `/admin/settings/admin-panel/*` and the panel root. Mail templates retain their own local tab bar because they belong to one mail module.

## Administrative visual system

KaevCMS 0.22.9 keeps the administration interface light and uses one component system across ordinary pages, catalogues, tables and server drawers:

- `admin-overview` provides the shared summary/action surface used by content, themes, users, administrators, audit, account security and the dashboard;
- `admin-card-list`, `admin-card-row` and `admin-card-grid` align catalogue rows and theme cards without removing their domain-specific layouts;
- `admin-table-wrap` and `admin-table` provide one table header, row-hover, border and responsive overflow model;
- `admin-filter-bar` and `admin-subtabs` align search filters and contextual navigation;
- `admin-card-heading`, `admin-actions-panel`, `admin-page-toolbar` and `admin-empty-state` provide predictable headings, save areas, back actions and empty screens;
- top-level Settings and Mail tabs continue to share `admin-tabs` / `admin-tab`;
- GameServer drawer tabs keep their document-tab shape while using the same palette;
- authentication pages consume the same surface, control, radius and focus tokens;
- responsive layouts remain usable at desktop, tablet and phone widths.

The source palette is defined in `public/assets/admin/css/app.css` through `--admin-*` variables. In addition to neutral surfaces, the token layer includes shared success, warning, danger and information states. Existing names such as `--accent`, `--surface`, `--border` and `--muted` remain compatibility aliases. A future dark theme should override the design tokens instead of copying component rules. The current release intentionally ships only the stabilized light palette.

Navigation groups are collapsed by default. The group containing the current page opens automatically, while manually opened or closed inactive groups are remembered in browser storage. On narrow screens the links remain available in the horizontal navigation without extra expansion.

Internal panel links use Livewire `wire:navigate`, so the document body is replaced without a full browser reload. The sidebar is wrapped in Livewire `@persist`, keeps its own scroll position with `wire:navigate:scroll`, and uses `wire:current` for active links because server-side route classes cannot be refreshed inside persisted DOM. Sidebar destinations use hover prefetching, while ordinary content links keep the gentler default strategy. Stable scrollbar space, fixed shell dimensions and a short opacity transition prevent layout jumps during page swaps.

`public/assets/admin/js/page-lifecycle.js` is loaded once from `<head>` and owns the `livewire:navigating` / `livewire:navigated` lifecycle. Page-specific scripts register one named initializer through `window.KaevCMSAdmin.registerPage(...)` and are marked `data-navigate-once`; the lifecycle reruns them for the new DOM and invokes returned cleanup callbacks before leaving the page. This prevents duplicated handlers and fixes widgets that must work after returning through SPA navigation or browser history. The navigation shell script is also loaded once and tracked by version. Livewire assets are loaded by the shared layout, and the built-in progress bar uses the panel accent color.

The panel language switcher is independent of the public theme. Russian and English are built in; the selected locale is stored in the administrator account.

## Theme activation

The active theme is stored in the CMS database under the key `theme.active`. The `.env` value `CMS_THEME` remains the safe fallback used when the settings table is unavailable or the selected theme becomes invalid.

Before activation, the core checks:

- safe directory slug;
- readable `theme.json`;
- required manifest fields;
- matching manifest and directory slugs;
- required Blade files;
- minimum and maximum CMS versions when declared.

Theme ZIP upload is deliberately not implemented yet. Archive extraction requires a separate security layer against path traversal, executable files, oversized archives, and unsafe symbolic links.


## Route organization

The web route entry point is intentionally small and loads three focused files:

```text
routes/public.php
routes/account.php
routes/admin.php
```

Public and account route names and URLs remain compatible. Administrative routes use an internal `{adminPath}` prefix checked by `EnsureAdminPath`. The middleware removes this infrastructure parameter before controller dispatch so it cannot replace real parameters such as a theme slug or model identifier.

## Reward queue

Owners and administrators can inspect the read-only reward-queue journal. Editors have no access. The journal shows whether KaevCMS confirmed writing the requested rows to `kaev_reward_queue`; actual item delivery remains under the GameServer administrator’s control. Unknown write outcomes are marked for review and keep inventory rows reserved.
