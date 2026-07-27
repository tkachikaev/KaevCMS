# GameServer reward queue / Очередь наград GameServer

## English

KaevCMS writes selected web-inventory rewards to the neutral `kaev_reward_queue` table in the selected GameServer database. It does not write to `items`, allocate `object_id`, patch GameServer sources, or require a specific consumer implementation.

Run `install.sql` once in every GameServer database that should accept rewards. One player transfer creates one immutable operation identified by `request_uuid`; every item is a separate row with its own `line_number`. The unique key `(request_uuid, line_number)` prevents a repeated CMS request from creating duplicate rows. `game_server_id` is copied into every row for diagnostics and audit correlation.

### Two separate status contracts

KaevCMS tracks the write operation in its own database:

- `pending` → `queued`, `review`, or `failed`;
- `review` → `queued`, `failed`, or remains `review`;
- `queued` and `failed` are terminal CMS states.

`queued` means the complete immutable payload exists in `kaev_reward_queue`. It does **not** mean the item was delivered to the character.

The administrator-owned consumer tracks each external queue row:

- `pending` → `processing`, `delivered`, or `failed`;
- `processing` → `delivered` or `failed`;
- `delivered` and `failed` are terminal in the supplied contract.

KaevCMS verifies the immutable payload but does not overwrite or interpret the consumer status during idempotency checks. A consumer-side `failed` row therefore remains evidence that the CMS write succeeded; reusing the same UUID must not create a second delivery.

The server owner chooses the consumer: GameServer plugin/script, stored procedure, scheduled database event, external service, or manual processing. `consumer-template.sql` is intentionally incomplete because object-ID allocation and inventory columns differ between Lineage II distributions.

Operational files:

- `pending.sql` — rows waiting for a consumer;
- `mark-processing.example.sql` — atomic claim of one pending row;
- `mark-delivered.example.sql` — terminal success after real item delivery;
- `mark-failed.example.sql` — terminal failure with a safe diagnostic message;
- `problematic.sql` — failed rows and stale `processing` rows for operator review.

## Русский

KaevCMS записывает выбранные награды веб-инвентаря в нейтральную таблицу `kaev_reward_queue` нужной базы GameServer. CMS не изменяет `items`, не создаёт `object_id`, не патчит исходники GameServer и не требует конкретной реализации обработчика.

Выполните `install.sql` один раз в каждой игровой базе, которая должна принимать награды. Одна передача игрока создаёт одну неизменяемую операцию с `request_uuid`; каждый предмет записывается отдельной строкой со своим `line_number`. Уникальный ключ `(request_uuid, line_number)` не позволяет повторному запросу CMS создать дубли. `game_server_id` сохраняется в каждой строке для диагностики и связи с audit trail.

### Два независимых контракта статусов

KaevCMS хранит результат записи в собственной базе:

- `pending` → `queued`, `review` или `failed`;
- `review` → `queued`, `failed` или остаётся `review`;
- `queued` и `failed` — конечные статусы CMS.

`queued` означает, что полный неизменяемый состав операции существует в `kaev_reward_queue`. Это **не** подтверждает фактическую выдачу предмета персонажу.

Обработчик владельца сервера управляет статусом каждой внешней строки:

- `pending` → `processing`, `delivered` или `failed`;
- `processing` → `delivered` или `failed`;
- `delivered` и `failed` являются конечными статусами поставляемого контракта.

KaevCMS проверяет неизменяемый состав операции, но не перезаписывает и не трактует статус обработчика при идемпотентной проверке. Поэтому строка `failed` со стороны обработчика всё равно подтверждает успешную запись CMS; повторное использование того же UUID не должно создавать вторую выдачу.

Владелец сервера сам выбирает обработчик: плагин/скрипт GameServer, хранимую процедуру, событие базы, внешний сервис или ручную обработку. `consumer-template.sql` намеренно не завершён, поскольку генерация object ID и структура игрового инвентаря различаются между сборками Lineage II.

Служебные файлы:

- `pending.sql` — строки, ожидающие обработчика;
- `mark-processing.example.sql` — атомарное получение одной строки в работу;
- `mark-delivered.example.sql` — конечный успех после реальной выдачи предмета;
- `mark-failed.example.sql` — конечная ошибка с безопасным описанием;
- `problematic.sql` — ошибки и зависшие строки `processing` для проверки оператором.
