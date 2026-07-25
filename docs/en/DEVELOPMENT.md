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
