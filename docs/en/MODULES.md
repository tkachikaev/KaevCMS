# Modules

Modules are trusted PHP code, not sandboxed plugins. Only the owner can install, enable, approve a changed version, apply migrations, disable, or remove a module. Administrators may inspect module state; editors have no module-management access.

Each module has a strictly validated manifest, immutable migration history, scoped routes, translations, views, and optional navigation entries. A modified or removed applied migration blocks runtime loading until the owner resolves the package. Failed migration batches roll back only their current changes.

The bundled `promo-codes` module grants one or more server-bound rewards to the core web inventory. Disabling or deleting a code preserves activation and reward history.

The bundled `daily-rewards` module adds separate monthly calendars for game servers. The current day reward is granted once per eligible game account through the core Web Inventory. See [Daily Rewards](DAILY_REWARDS.md).

Browser ZIP installation, automatic remote updates, and sandbox isolation are intentionally not provided yet.
