# Update delivery / Доставка обновлений

## English

Shared hosting uses the Web Updater. Ubuntu VDS installations use the same cumulative package through `php artisan kaevcms:update` as the deployment user.

The package manifest, version range, paths, payload hashes, backups, and recovery state are verified. Current packages must still be obtained from a trusted source because publisher signatures are not yet enabled.

Read the current guide: [UPDATES.md](en/UPDATES.md).

## Русский

На shared-hosting используется Web Updater. На Ubuntu VDS тот же кумулятивный пакет применяется deployment-пользователем через `php artisan kaevcms:update`.

Проверяются manifest, диапазон версий, пути, хеши файлов, резервные копии и состояние восстановления. Пакет пока необходимо получать из доверенного источника, потому что отдельная подпись издателя ещё не включена.

Актуальная инструкция: [UPDATES.md](ru/UPDATES.md).
