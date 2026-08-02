# Update delivery / Доставка обновлений

## English

Shared hosting applies packages directly through the Web Updater. Ubuntu VDS installations can install the local systemd agent with `bash deployment/vds/install-update-agent.sh`, after which the same Web Updater delegates installation to the project owner. The installer requests sudo automatically, detects whether the project is owned by root or a regular account, and configures the service accordingly. Root mode prints an explicit trusted-package warning. The CLI command `php artisan kaevcms:update` remains available as a fallback.

The package manifest, version range, paths, payload hashes, backups, and recovery state are verified. Current packages must still be obtained from a trusted source because publisher signatures are not yet enabled.

Read the current guide: [UPDATES.md](en/UPDATES.md).

## Русский

На shared-hosting Web Updater применяет пакет напрямую. На Ubuntu VDS можно установить локальный systemd-agent командой `bash deployment/vds/install-update-agent.sh`, после чего Web Updater передаёт установку владельцу файлов проекта. Установщик сам запросит sudo, определит владельца проекта и настроит службу. В режиме root он выведет отдельное предупреждение о доверии к пакету. Команда `php artisan kaevcms:update` остаётся резервным способом.

Проверяются manifest, диапазон версий, пути, хеши файлов, резервные копии и состояние восстановления. Пакет пока необходимо получать из доверенного источника, потому что отдельная подпись издателя ещё не включена.

Актуальная инструкция: [UPDATES.md](ru/UPDATES.md).
