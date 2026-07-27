# Development and quality

## Required checks

```powershell
.\deployment\windows\quality.ps1
.\deployment\windows\browser-quality.ps1
```

The offline quality gate runs update-workflow regressions, Composer policy tests, Web Installer and shared-hosting regressions, Web Update package checks, Composer validation, Pint, PHPStan, PHPUnit, and route-cache verification. Browser quality installs the locked npm dependencies and runs Playwright tests.

Do not weaken a regression to obtain a green result. Add tests for every bug fix, especially installation, updates, authentication, database boundaries, and external game-server failures.

## Release discipline

- Increment `VERSION` for every delivered change.
- Update `README.md` and `CHANGELOG.md`.
- Ship full, patch, Web Update, and SHA256 artifacts.
- Verify ZIP integrity and portable path separators.
- Confirm previous release plus patch equals the full target release.
- Record changed migrations and Composer/npm locks explicitly.

## Front-end asset architecture

The administration layout loads the following stylesheets in this exact order:

```text
public/assets/admin/css/base.css
public/assets/admin/css/layout.css
public/assets/admin/css/content.css
public/assets/admin/css/infrastructure.css
public/assets/admin/css/components.css
public/assets/admin/css/extensions.css
public/assets/admin/css/catalogs.css
```

Keep the order stable: later files intentionally refine earlier primitives. Do not recreate a monolithic `app.css` or add page fixes as untracked tail overrides. Place new rules in the narrowest matching file and add a browser regression when layout behavior changes.

Both bundled player-account themes use the shared versioned runtime:

```text
public/assets/account/js/navigation.js
```

Theme-specific JavaScript copies are not shipped. Theme layouts may keep their own CSS and Blade markup, but persistent Livewire navigation, avatar/result dialogs, sidebar toggling, password controls, and navigation cleanup belong to the shared runtime.
