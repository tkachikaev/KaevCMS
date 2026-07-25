# GameServer reward queue / Очередь наград GameServer

## English

KaevCMS writes selected web-inventory rewards to the neutral `kaev_reward_queue` table in the selected GameServer database. It does not write to `items`, allocate `object_id`, patch GameServer sources, or require a heartbeat/protocol version.

Run `install.sql` once in every GameServer database that should accept rewards. One player transfer may create several rows sharing `request_uuid` and using different `line_number` values. Recommended statuses are `pending`, `processing`, `delivered`, and `failed`.

The server owner chooses the consumer:

- GameServer plugin or script;
- stored procedure or scheduled database event;
- external service;
- manual processing.

`consumer-template.sql` is intentionally incomplete because object-ID allocation and inventory columns differ between Lineage II distributions. Operational examples are provided in `pending.sql`, `mark-delivered.example.sql`, and `mark-failed.example.sql`.

## Русский

KaevCMS записывает выбранные награды веб-инвентаря в нейтральную таблицу `kaev_reward_queue` нужной базы GameServer. CMS не изменяет `items`, не создаёт `object_id`, не патчит исходники GameServer и не требует heartbeat или версии протокола.

Выполните `install.sql` один раз в каждой игровой базе, которая должна принимать награды. Один перенос игрока может создать несколько строк с общим `request_uuid` и разными `line_number`. Рекомендуемые статусы: `pending`, `processing`, `delivered` и `failed`.

Владелец сервера сам выбирает обработчик:

- плагин или скрипт внутри GameServer;
- хранимая процедура или плановое событие базы;
- внешний сервис;
- ручная обработка.

`consumer-template.sql` намеренно не завершён: генерация object ID и состав колонок инвентаря различаются между сборками Lineage II. Рабочие примеры находятся в `pending.sql`, `mark-delivered.example.sql` и `mark-failed.example.sql`.
