# Modules

Modules are trusted PHP code, not sandboxed plugins. Only the owner can install, enable, approve a changed version, apply migrations, disable, or remove a module. Administrators may inspect module state; editors have no module-management access.

Each module has a strictly validated manifest, immutable migration history, scoped routes, translations, views, and optional navigation entries. A modified or removed applied migration blocks runtime loading until the owner resolves the package. Failed migration batches roll back only their current changes.

The bundled `promo-codes` module grants one or more server-bound rewards to the core web inventory. Disabling or deleting a code preserves activation and reward history.

The bundled `daily-rewards` module adds separate monthly calendars for game servers. The current day reward is granted once per eligible game account through the core Web Inventory. See [Daily Rewards](DAILY_REWARDS.md).


A module may provide optional catalogue artwork at `assets/module.webp`. KaevCMS auto-discovers it when it is a valid 512×512 WebP file no larger than 2 MB; otherwise the administration catalogue keeps the letter placeholder.

Promo Codes and Daily Rewards use the shared account operation dialog for success and failure results, including granted item icons and amounts.

Browser ZIP installation, automatic remote updates, and sandbox isolation are intentionally not provided yet.

The bundled `support-tickets` module adds private player tickets. Owners and administrators can reply, auditors are read-only, and Editor access is enabled separately in the module settings. Documentation is stored at `modules/support-tickets/docs/README.en.md`.
