# Windows tooling / Windows-инструменты

## English

All scripts resolve the project root from `$PSScriptRoot` and may be started from any working directory.

```powershell
.\deployment\windows\setup.ps1
.\deployment\windows\quality.ps1
.\deployment\windows\browser-quality.ps1
.\deployment\windows\build-shared-hosting-package.ps1 -PublicDirectoryName public_html
```

The shared-hosting builder accepts `-PublicDirectoryName`, `-CoreDirectoryName`, `-OutputDirectory`, and the diagnostic-only `-IncludeDevelopmentDependencies` switch. No interactive provider menu is used.

## Русский

Все скрипты определяют корень проекта через `$PSScriptRoot` и могут запускаться из любого текущего каталога.

```powershell
.\deployment\windows\setup.ps1
.\deployment\windows\quality.ps1
.\deployment\windows\browser-quality.ps1
.\deployment\windows\build-shared-hosting-package.ps1 -PublicDirectoryName public_html
```

Сборщик shared-hosting принимает `-PublicDirectoryName`, `-CoreDirectoryName`, `-OutputDirectory` и диагностический ключ `-IncludeDevelopmentDependencies`. Интерактивного меню провайдеров нет.
