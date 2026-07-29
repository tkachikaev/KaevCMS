# Reproducible release builder / Воспроизводимая сборка релиза

The unified builder creates the four official artifacts from the current source tree and the direct previous full release:

```powershell
.\deployment\windows\build-release.ps1 `
    -PreviousFullArchive "C:\Releases\KaevCMS-0.44.8-full.zip" `
    -OutputDirectory "C:\Releases\0.44.13"
```

It builds and verifies:

- the complete source archive;
- the direct previous-to-current patch;
- the cumulative Web Update from the `release.json` baseline through the direct previous version;
- the SHA256 checksum file.

The build stops when metadata is inconsistent, a required file is missing, an unsafe ZIP path or symbolic link is found, runtime-owned data would enter the release, a removed file is absent from `deletions.json`, or previous full plus patch does not exactly match the target release tree. ZIP timestamps are derived from `released_at` so repeated builds from the same source are deterministic.

---

Единый сборщик создаёт четыре официальных артефакта из текущего дерева исходников и полного архива прямой предыдущей версии:

```powershell
.\deployment\windows\build-release.ps1 `
    -PreviousFullArchive "C:\Releases\KaevCMS-0.44.8-full.zip" `
    -OutputDirectory "C:\Releases\0.44.13"
```

Он собирает и проверяет:

- полный архив исходников;
- прямой patch предыдущая → текущая версия;
- cumulative Web Update от baseline из `release.json` до прямой предыдущей версии;
- файл контрольных сумм SHA256.

Сборка останавливается при противоречивых metadata, отсутствии обязательного файла, небезопасном ZIP-пути или symlink, попадании runtime-данных, незаявленном удалении либо несовпадении результата «предыдущий full + patch» с целевым деревом. Время ZIP-записей берётся из `released_at`, поэтому повторная сборка одного исходного дерева детерминирована.
