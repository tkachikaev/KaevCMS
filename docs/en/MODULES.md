# Modules

Modules are trusted PHP code, not sandboxed plugins. Only the owner can install, enable, approve a changed version, apply migrations, disable, or remove a module. Administrators may inspect module state; editors have no module-management access.

Each module has a strictly validated manifest, immutable migration history, scoped routes, translations, views, and optional navigation entries. A modified or removed applied migration blocks runtime loading until the owner resolves the package. Failed migration batches roll back only their current changes.

The bundled `promo-codes` module grants one or more server-bound rewards to the core web inventory. Disabling or deleting a code preserves activation and reward history.

The bundled `daily-rewards` module adds separate monthly calendars for game servers. The current day reward is granted once per eligible game account through the core Web Inventory. See [Daily Rewards](DAILY_REWARDS.md).


A module may provide optional catalogue artwork at `assets/module.webp`. KaevCMS auto-discovers it when it is a valid 512×512 WebP file no larger than 2 MB; otherwise the administration catalogue keeps the letter placeholder.

Promo Codes and Daily Rewards use the shared account operation dialog for success and failure results, including granted item icons and amounts.

Browser ZIP installation, automatic remote updates, and sandbox isolation are intentionally not provided yet.

The bundled `support-tickets` module adds private player tickets. Owners and administrators can reply, auditors are read-only, and Editor access is enabled separately in the module settings. New tickets and player replies also create personal administrator notification-center events without copying private message text. Documentation is stored at `modules/support-tickets/docs/README.en.md`.
## Administration Livewire authorization

Registering an administration route in `ModuleAdminAccessRegistry` protects the normal HTTP route, but does not automatically transfer that access decision to the shared `/livewire/update` endpoint. A module administration Livewire component must use `AuthorizesModuleAdminAccess` and authorize the registered route name before every public action.

Basic pattern:

```php
use App\Support\Modules\AuthorizesModuleAdminAccess;

final class ExampleComponent extends Component
{
    use AuthorizesModuleAdminAccess;

    public function save(): void
    {
        $this->authorizeModuleAdminRoute(
            'admin.module-pages.example.update',
            'PUT',
        );

        // Mutate data only after authorization.
    }
}
```

`ModuleAdminAuthorizer` is the single decision source used by middleware and Livewire. Do not duplicate role lists inside the component. Add regressions for Owner, Administrator, Editor, Auditor, and unauthenticated callers for every public Livewire action. Unknown route names fail closed.

