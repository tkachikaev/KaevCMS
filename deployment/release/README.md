# Reproducible release builder / Воспроизводимая сборка релиза

The unified builder creates the official release artifacts from the current source tree and the direct previous full release:

```powershell
.\deployment\windows\build-release.ps1 `
    -PreviousFullArchive "C:\Releases\KaevCMS-<previous>-full.zip" `
    -OutputDirectory "C:\Releases\<version>"
```

It builds and verifies:

- the complete source archive (`KaevCMS-<version>-full.zip`);
- the direct previous-to-current patch (`KaevCMS-<previous>-to-<version>-patch.zip`);
- the cumulative Web Update from the `release.json` baseline through the direct previous version (`KaevCMS-cumulative-update-0.42.4-<previous>-to-<version>.zip`), including the production `vendor` prepared from `composer.lock`;
- the bridge Web Update for sites still on 0.42.4 through 0.50.16 (`KaevCMS-bridge-update-0.42.4-0.50.16-for-<version>.zip`), built while such sites are supported;
- the SHA256 checksum file (`KaevCMS-<version>-SHA256SUMS.txt`) for all of the above.

The shared-hosting package is not part of this command. Build it separately with `.\deployment\windows\build-shared-hosting-package.ps1` (see [Shared hosting](../../docs/en/SHARED_HOSTING.md)).

The build stops when metadata is inconsistent, a required file is missing, an unsafe ZIP path or symbolic link is found, runtime-owned data would enter the release, a removed file is absent from `deletions.json`, or previous full plus patch does not exactly match the target release tree. It also verifies the target and previous apply-script contract plus the current and previous `composer.lock` hashes against the actual trees. ZIP timestamps are derived from `released_at` so repeated builds from the same source are deterministic.

Files changed between the previous full archive and the target tree are discovered automatically. `release.json.repair_files` is reserved only for exceptional forced replacement of files that are unchanged between those canonical trees; listing an already changed file as a repair file is rejected as stale metadata.

---

Единый сборщик создаёт официальные артефакты релиза из текущего дерева исходников и полного архива прямой предыдущей версии:

```powershell
.\deployment\windows\build-release.ps1 `
    -PreviousFullArchive "C:\Releases\KaevCMS-<предыдущая>-full.zip" `
    -OutputDirectory "C:\Releases\<версия>"
```

Он собирает и проверяет:

- полный архив исходников (`KaevCMS-<версия>-full.zip`);
- прямой patch предыдущая → текущая версия (`KaevCMS-<предыдущая>-to-<версия>-patch.zip`);
- cumulative Web Update от baseline из `release.json` до прямой предыдущей версии (`KaevCMS-cumulative-update-0.42.4-<предыдущая>-to-<версия>.zip`) вместе с production-библиотеками `vendor`, подготовленными по `composer.lock`;
- пакет-мост для сайтов на версиях с 0.42.4 по 0.50.16 (`KaevCMS-bridge-update-0.42.4-0.50.16-for-<версия>.zip`), пока такие сайты поддерживаются;
- файл контрольных сумм SHA256 (`KaevCMS-<версия>-SHA256SUMS.txt`) для всех перечисленных архивов.

Пакет для обычного хостинга этой командой не создаётся. Соберите его отдельно через `.\deployment\windows\build-shared-hosting-package.ps1` (см. [Обычный хостинг](../../docs/ru/SHARED_HOSTING.md)).

Сборка останавливается при противоречивых metadata, отсутствии обязательного файла, небезопасном ZIP-пути или symlink, попадании runtime-данных, незаявленном удалении либо несовпадении результата «предыдущий full + patch» с целевым деревом. Дополнительно проверяются apply-скрипты текущей и предыдущей версии, а также SHA256 текущего и предыдущего `composer.lock` по фактическим деревьям. Время ZIP-записей берётся из `released_at`, поэтому повторная сборка одного исходного дерева детерминирована.

Изменённые файлы определяются автоматически сравнением предыдущего full с целевым деревом. `release.json.repair_files` используется только для исключительного принудительного обновления файлов, которые не изменились между эталонными деревьями; если туда случайно попал уже изменённый файл, сборка отклоняет устаревшие metadata.
