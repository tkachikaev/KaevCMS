# System diagnostics / Системная диагностика

## English

The system page reports version, scheduler, queues, encryption health, database safety, proxy configuration, and redacted LoginServer/GameServer diagnostics. External diagnostics include availability, last success, safe error class, SELECT 1 latency, schema profile, capabilities, and required/optional table state.

Read the current guide: [ADMINISTRATION.md](en/ADMINISTRATION.md).

## Русский

Системная страница показывает версию, планировщик, очереди, шифрование, безопасность базы, proxy и обезличенную диагностику LoginServer/GameServer: доступность, последнее успешное подключение, безопасный класс ошибки, задержку SELECT 1, профиль схемы, возможности драйвера и состояние обязательных/необязательных таблиц.

Актуальная инструкция: [ADMINISTRATION.md](ru/ADMINISTRATION.md).

### Database PHP drivers

`pdo_mysql` is required for MySQL/MariaDB and Lineage II database connections. `pdo_sqlite` is required only when the CMS database itself uses SQLite. On a MySQL/MariaDB installation, a missing `pdo_sqlite` extension is informational and must not be reported as a critical requirement.

### Драйверы PHP для базы данных

`pdo_mysql` обязателен для MySQL/MariaDB и подключений к базам Lineage II. `pdo_sqlite` обязателен только тогда, когда сама база CMS работает на SQLite. На установке с MySQL/MariaDB отсутствие `pdo_sqlite` является справочной информацией и не должно отображаться как критическое требование.
