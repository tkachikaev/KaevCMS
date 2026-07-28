# Windows tooling / Windows-инструменты

## English

All scripts resolve the project root from `$PSScriptRoot` and may be started from any working directory.

```powershell
.\deployment\windows\setup.ps1
.\deployment\windows\quality.ps1
.\deployment\windows\browser-quality.ps1
.\deployment\windows\build-shared-hosting-package.ps1 -PublicDirectoryName public_html
.\deployment\windows\build-release.ps1 -PreviousFullArchive C:\Releases\KaevCMS-0.41.8-full.zip
```

The shared-hosting builder accepts `-PublicDirectoryName`, `-CoreDirectoryName`, `-OutputDirectory`, and the diagnostic-only `-IncludeDevelopmentDependencies` switch. `build-release.ps1` creates and verifies full, patch, cumulative, and SHA256 artifacts from the direct previous full archive. No interactive provider menu is used.

## Русский

Все скрипты определяют корень проекта через `$PSScriptRoot` и могут запускаться из любого текущего каталога.

```powershell
.\deployment\windows\setup.ps1
.\deployment\windows\quality.ps1
.\deployment\windows\browser-quality.ps1
.\deployment\windows\build-shared-hosting-package.ps1 -PublicDirectoryName public_html
.\deployment\windows\build-release.ps1 -PreviousFullArchive C:\Releases\KaevCMS-0.41.8-full.zip
```

Сборщик shared-hosting принимает `-PublicDirectoryName`, `-CoreDirectoryName`, `-OutputDirectory` и диагностический ключ `-IncludeDevelopmentDependencies`. `build-release.ps1` создаёт и проверяет full, patch, cumulative и SHA256 по полному архиву прямой предыдущей версии. Интерактивного меню провайдеров нет.
