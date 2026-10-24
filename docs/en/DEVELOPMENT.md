# Development and quality

## Required checks

```powershell
.\deployment\windows\quality.ps1
.\deployment\windows\browser-quality.ps1
```

The offline quality gate runs Windows Update, Composer policy, Web Installer, shared-hosting, Web Update, unified release-builder, Composer validation, Pint, PHPStan, PHPUnit, and route-cache checks. Browser quality uses dependencies prepared by `browser-setup.ps1` and runs Playwright.

Windows quality supports Windows PowerShell 5.1 and PowerShell 7; CI checks both editions. An empty environment value means removal in the shared deployment helpers, including restoration of Composer network policy after an audit.

The `.gitattributes` rules preserve LF for `composer.lock`, CSS, JavaScript, shell scripts and PHPStan configuration; PowerShell scripts use CRLF. This is part of reproducible release packaging: checksums compare actual bytes, so do not change `composer.lock` or its expected SHA256 merely to accommodate line-ending conversion in a local Git checkout.

Do not weaken a regression to obtain a green result. Add tests for every bug fix, especially installation, updates, authentication, database boundaries, and external game-server failures.

## Release discipline

`release.json` is the authoritative release contract. `VERSION` is a compatibility mirror and must match it. The contract defines the target and previous versions, apply-script paths, dependency fingerprints, recovery floor, and cumulative-package base. `deployment/release-files.json` lists files that must exist after extraction, while `deployment/windows/update-contract.json` defines runtime directories, protected environment values, and the update-stage order.

- Change release lineage only in the validated contracts, then update the `VERSION` mirror, `README.md`, and `CHANGELOG.md`.
- Never hard-code current or previous versions into regression source snippets.
- Ship full, patch, cumulative Web Update, bridge (while sites on 0.42.4 through 0.50.16 are supported), and SHA256 artifacts.
- Verify ZIP integrity and portable path separators.
- Confirm previous release plus patch equals the full target release.
- Record changed migrations and Composer/npm locks explicitly.

Build every official artifact with one command:

```powershell
.\deployment\windows\build-release.ps1 `
    -PreviousFullArchive "C:\Releases\KaevCMS-<previous>-full.zip" `
    -OutputDirectory "C:\Releases\<version>"
```

See [deployment/release/README.md](../../deployment/release/README.md) for the full list of artifacts.

The builder proves that previous full plus patch exactly matches the target tree, keeps the cumulative baseline at `0.42.4`, excludes runtime-owned files, and writes verified SHA256 values. GitHub Actions separately runs PHP, official Windows quality, official Windows browser quality, and release-contract jobs.

## Composer dependencies: owner approval only

**Do not update Laravel, Livewire or any Composer dependency, and do not change `composer.json` or `composer.lock`, without prior explicit approval from the KaevCMS owner.** This applies to new features, bug fixes, security audits and release preparation alike. Every release package delivers `composer.lock` to all sites, and sites on older releases can only cross a dependency change through the bridge package.

The approved state is recorded in `deployment/dependencies/approved-dependencies.json` (SHA-256 of both files, key package versions, who approved and why). `php deployment/dependencies/dependency-guard.php` compares the working tree with it. The Windows quality gate, every GitHub Actions job, `build-release.ps1`, `build-release.php` and `build-web-update-package.ps1` run the guard and fail on any unapproved change. `composer install` from the approved `composer.lock` is never blocked: the guard is not a Composer hook.

To approve a dependency change agreed with the owner:

1. Make the change (`composer update <package>`) on a separate branch.
2. The owner reviews it and runs, in an interactive terminal:

   ```powershell
   php deployment/dependencies/dependency-guard.php --approve --reason="Laravel 13.39 security fix"
   ```

   The command lists the changed files and the old and new Laravel and Livewire versions, then asks the owner to type a confirmation phrase bound to these exact files. Without an interactive terminal (CI, scripts, automated assistants) approval is refused.
3. Commit `approved-dependencies.json` together with `composer.json` and `composer.lock`, and update `composer_lock.current_sha256` in `release.json` for the next release.

To discard an unapproved change instead: `git checkout -- composer.json composer.lock`.

## Front-end asset architecture

Typography, spacing, colours, number formatting and screen states of the administration panel are defined in the [design canon](DESIGN.md); new and redesigned screens follow it.

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

The administration Content Security Policy admits an inline `<style>` only with the current response's one-time nonce (`Vite::cspNonce()`, issued by `AdminSecurityHeaders`). Livewire (`@livewireStyles`, the navigation progress bar) and the content editor (`injectNonce`) use it. `style="…"` attributes in markup are blocked: style with classes in CSS files, and from JavaScript through `element.style`. One blocked-style report during Livewire navigation inside administration is expected: Livewire parses the new page before substituting the current nonce, and rendering is unaffected.

Both bundled player-account themes use the shared versioned runtime:

```text
public/assets/account/js/navigation.js
```

Theme-specific JavaScript copies are not shipped. Theme layouts may keep their own CSS and Blade markup, but persistent Livewire navigation, avatar/result dialogs, sidebar toggling, password controls, and navigation cleanup belong to the shared runtime.

## Building the content editor

The news/page editor keeps readable source in `resources/js/admin/content-editor.js` and ships the autonomous `public/assets/admin/js/news-editor.js` bundle. Tiptap and esbuild are pinned in `package-lock.json`; no CDN is used.

After changing the source, run:

```powershell
npm ci --include=dev
npm run build:editor
```

A release must include the source, bundle, and `public/assets/admin/js/news-editor.LICENSE.txt`. Do not allow arbitrary `style` or `class` attributes: the editor, `SafeHtmlSanitizer`, and both public-theme stylesheets form one safe `data-*` token contract.

## Player account components

Account pages and module views use the shared `<x-account-*>` components and the `.account-tabs` class. The contract is described in [Content and themes](CONTENT_THEMES.md#account-theme-components).

## Aurelia navigation between the website and player account

Public and account layouts have independent global stylesheets. Aurelia account links in the public header, mobile menu, hero and footer deliberately use normal document navigation. Do not add wire:navigate to these links: Livewire swaps the body before destination stylesheets finish loading, which can briefly expose oversized black default SVG shapes. Document navigation waits for render-blocking CSS and keeps styles from different layouts separate. Navigation within each layout continues to use Livewire.

Aurelia document entry gently animates body opacity from .88 to 1 over 160 ms once, without moving controls or blanking the page. The account layout enables this through data-account-document-entry and the shared runtime. Reduced-motion disables the effect. Native cross-document View Transitions are not used; browser checks exposed a hang with mixed themes.

Public initialization treats DOMContentLoaded and the initial livewire:navigated event as one page initialization. Repeated bootstrap notifications do not replay the content fade or recreate particles/monitoring. A real livewire:navigating event releases the resources and allows initialization of new content.

Regressions: `aurelia-navigation.spec.mjs` checks the initial initialization and real content replacement; `player-character-directory.spec.mjs` checks site ↔ account round trips with a 350 ms stylesheet delay, the absence of large filled SVG shapes, desktop/mobile and reduced-motion. On mobile the return link to the website is exercised after opening the sidebar.
