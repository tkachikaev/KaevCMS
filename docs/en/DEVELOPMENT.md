# Development and quality

## Required checks

```powershell
.\deployment\windows\quality.ps1
.\deployment\windows\browser-quality.ps1
```

The offline quality gate runs update-workflow regressions, Composer policy tests, Web Installer and shared-hosting regressions, Web Update package checks, Composer validation, Pint, PHPStan, PHPUnit, and route-cache verification. Browser quality installs the locked npm dependencies and runs Playwright tests.

Do not weaken a regression to obtain a green result. Add tests for every bug fix, especially installation, updates, authentication, database boundaries, and external game-server failures.

## Release discipline

`release.json` is the authoritative release contract. `VERSION` is a compatibility mirror and must match it. The contract defines the target and previous versions, apply-script paths, dependency fingerprints, recovery floor, and cumulative-package base. `deployment/release-files.json` lists files that must exist after extraction, while `deployment/windows/update-contract.json` defines runtime directories, protected environment values, and the update-stage order.

- Change release lineage only in the validated contracts, then update the `VERSION` mirror, `README.md`, and `CHANGELOG.md`.
- Never hard-code current or previous versions into regression source snippets.
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
