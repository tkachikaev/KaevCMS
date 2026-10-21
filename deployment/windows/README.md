# Windows tooling / Windows-инструменты

## English

All scripts resolve the project root from `$PSScriptRoot` and may be started from any working directory.

```powershell
.\deployment\windows\setup.ps1
.\deployment\windows\quality.ps1
.\deployment\windows\browser-quality.ps1
.\deployment\windows\build-shared-hosting-package.ps1 -PublicDirectoryName public_html
.\deployment\windows\build-release.ps1 -PreviousFullArchive "C:\Releases\KaevCMS-<previous>-full.zip"
```

The shared-hosting builder accepts `-PublicDirectoryName`, `-CoreDirectoryName`, `-OutputDirectory`, and the diagnostic-only `-IncludeDevelopmentDependencies` switch. `build-release.ps1` creates and verifies the full, patch, cumulative, and bridge (while sites on 0.42.4 through 0.50.16 are supported) archives plus the SHA256 checksum file from the direct previous full archive. See [deployment/release/README.md](../release/README.md). No interactive provider menu is used.

## Русский

Все скрипты определяют корень проекта через `$PSScriptRoot` и могут запускаться из любого текущего каталога.

```powershell
.\deployment\windows\setup.ps1
.\deployment\windows\quality.ps1
.\deployment\windows\browser-quality.ps1
.\deployment\windows\build-shared-hosting-package.ps1 -PublicDirectoryName public_html
.\deployment\windows\build-release.ps1 -PreviousFullArchive "C:\Releases\KaevCMS-<предыдущая>-full.zip"
```

Сборщик shared-hosting принимает `-PublicDirectoryName`, `-CoreDirectoryName`, `-OutputDirectory` и диагностический ключ `-IncludeDevelopmentDependencies`. `build-release.ps1` создаёт и проверяет архивы full, patch, cumulative и мост (пока поддерживаются сайты на версиях с 0.42.4 по 0.50.16), а также файл контрольных сумм SHA256 по полному архиву прямой предыдущей версии. См. [deployment/release/README.md](../release/README.md). Интерактивного меню провайдеров нет.
