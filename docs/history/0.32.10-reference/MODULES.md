# Modules

KaevCMS 0.26.0 provides a stable module runtime, guarded database migrations, optional navigation links and GameServer dependency guards.

## Installation

1. Copy the complete module directory into `modules/`.
2. Open **Control panel → Modules**.
3. Review manifest validity, CMS compatibility and pending database migrations.
4. Enable the module as an owner.

When a module declares migrations, KaevCMS applies them before approving the module version and before loading any module PHP code. A failed migration leaves the module inactive or temporarily unavailable.

Modules are not uploaded through the browser in this release. This avoids extracting untrusted archives from the web process and keeps file ownership predictable on shared hosting and Linux servers.

## Manifest

Every module requires `module.json`. The current manifest schema is `1`; its machine-readable reference is `resources/schemas/module.schema.json`.

Required fields:

- `schema` — currently `1`;
- `id` — lowercase directory identifier, equal to the directory name;
- `name`;
- `version` — semantic version;
- `author`.

Compatibility fields:

- `cms_min`;
- `cms_max`.

Optional runtime fields:

- `namespace` and `autoload` for an isolated PSR-4 namespace;
- `bootstrap` for a PHP file returning a callable;
- `views`;
- `lang`;
- `routes.web`;
- `routes.admin`;
- `migrations` for a directory containing module database migrations.

Example:

```json
{
  "schema": 1,
  "id": "promo-codes",
  "name": "Promo Codes",
  "version": "1.0.0",
  "author": "KaevCMS",
  "cms_min": "0.24.1",
  "cms_max": null,
  "namespace": "KaevCMS\\Modules\\PromoCodes\\",
  "autoload": "src",
  "bootstrap": "bootstrap.php",
  "views": "resources/views",
  "lang": "lang",
  "migrations": "database/migrations",
  "routes": {
    "web": "routes/web.php",
    "admin": "routes/admin.php"
  }
}
```

## Module migrations

Migration files use the standard Laravel migration contract and must return an `Illuminate\Database\Migrations\Migration` instance.

The directory is declared explicitly:

```json
{
  "migrations": "database/migrations"
}
```

File names must be deterministic and lowercase:

```text
2026_07_21_000001_create_promo_codes_table.php
2026_07_21_000002_create_promo_code_activations_table.php
```

Example migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_promo_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_promo_codes');
    }
};
```

KaevCMS stores each completed file and its SHA-256 checksum in `cms_module_migrations`.

Rules:

- migrations run in filename order;
- each migration runs only once for each module;
- a module cannot load while migrations are pending;
- replacing a module with a newer version requires owner approval;
- adding a migration without changing the module version still blocks runtime until the owner applies the database update;
- an already applied migration must never be edited, renamed or removed;
- schema changes must always be delivered as a new migration file;
- disabling a module never rolls back its migrations or removes module data.

A per-module atomic cache lock prevents two administrator requests from running the same migration batch concurrently.

If a migration fails, KaevCMS attempts to run `down()` for the failing migration and for migrations completed in the current batch. Previous successful batches are preserved. Because MySQL and MariaDB may auto-commit DDL operations, rollback is best effort: every migration must implement a safe `down()` method and should keep each file focused on one reversible schema change.

Failure diagnostics store only the migration stage and exception class. Full exception messages and secrets are not copied into module state or the administrative interface.

## Runtime contracts

The bootstrap file must return a callable:

```php
<?php

use App\Support\Modules\ModuleContext;
use Illuminate\Contracts\Foundation\Application;

return static function (Application $app, ModuleContext $module): void {
    // Register module services here.
};
```

View and translation namespaces are `module-{id}`. Public module routes are grouped under `/modules/{id}`. Administrator routes are grouped under the dynamic administrator address at `/extensions/{id}` and always receive the CMS administrator authentication, security-header and access middleware.

Every module route also receives a state guard. Disabled, missing, damaged, migration-pending, migration-failed or update-pending modules cannot execute. Module routes intentionally remain outside the core Laravel route cache and are registered after cached core routes, so changing module state never leaves an executable stale route and never requires clearing the site route cache.

If bootstrap or runtime loading fails, KaevCMS records only the safe loading stage and exception class, leaves the rest of the CMS available, and retries the module after a short cooldown. The default cooldown is 60 seconds, which prevents a broken module from flooding the log on every request.


## Optional navigation and GameServer guards

An enabled module can register links through `ModuleNavigationRegistry`. Account links must use the module public route namespace, and administrator links must use its protected module-page namespace. Links are rendered only while their route exists.

A module that stores a `game_server_id` can register a check through `ModuleGameServerDependencyRegistry`. If the check reports related data or fails unexpectedly, KaevCMS blocks destructive GameServer deletion. Database foreign keys should still use `restrictOnDelete()` as the final integrity boundary.

The bundled promo-code module demonstrates both contracts in `modules/promo-codes/bootstrap.php`.

## Permissions and audit

- Owner: view, install, update, enable and disable modules.
- Administrator: view module state in read-only mode.
- Editor: no module access.

KaevCMS audits:

- installation with migrations;
- database updates;
- version approval;
- activation and deactivation;
- failed migration attempts;
- failed state changes.

## Security model

Modules are trusted PHP code and are not sandboxed. Manifest validation protects the CMS from malformed identifiers, unknown fields, path traversal, unsafe symbolic links, incompatible versions and modified migration history, but it cannot make malicious PHP safe.

Do not enable a module unless you trust its source and have reviewed its release archive.

## Not included yet

The module system does not yet provide:

- browser ZIP installation;
- automatic module updates;
- module-owned settings UI;
- destructive data removal;
- a module marketplace;
- fine-grained permissions declared by each module.

Full deletion of module data must remain a separate, explicitly confirmed operation in a future release.

## Reward-producing modules

Promo-code, donation, voting and event modules must grant items through `RewardInventoryService` with an immutable source operation key. Modules must not write directly to a GameServer `items` table. Item icons should be resolved through `GameAssetUrlResolver`; see `docs/GAME_ASSETS.md`.
