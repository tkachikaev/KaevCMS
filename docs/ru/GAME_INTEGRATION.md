# Интеграция с игровыми серверами

KaevCMS разделяет собственную базу CMS и внешние базы LoginServer/GameServer.

## Драйверы

KaevCMS поддерживает два семейства игровых серверов:

- **L2J Mobius** — идентификатор `l2j_mobius`. LoginServer и GameServer намеренно используют одинаковый идентификатор в двух отдельных реестрах. Legacy-вариант LoginServer для C1/C4 сохраняет идентификатор `l2j_mobius_legacy`.
- **RUSaCis** — идентификатор `rusacis`, со своими драйверами LoginServer и GameServer. См. раздел [RUSaCis GameServer](#rusacis-gameserver) ниже.

GameServer-драйвер L2J Mobius использует профили схем вместо дублирования драйвера для каждой хроники. Inspector выбирает legacy-профиль при наличии `characters.karma` и modern-профиль при использовании `characters.reputation`. Обязательные игровые таблицы: `characters` и `clan_data`. Полные таблицы `heroes` и `castle` включают соответствующие необязательные разделы статистики; отсутствие или неполная структура этих таблиц не отключают рейтинги персонажей.

Проверка подключения и runtime-запросы используют единый контракт обязательных таблиц и колонок, не выводя реквизиты. Публичная статистика включается отдельно для каждого GameServer и имеет независимые лимиты рейтингов уровня, PvP, PK и игрового времени.

## Аккаунты и персонажи

Создание игрового аккаунта выполняется как сохраняемая операция с уникальным UUID и состояниями `pending → active | failed`. До записи CMS проверяет, что логина нет в LoginServer. После INSERT она повторно читает `accounts` и сверяет логин, драйверный хеш пароля и нормализованную почту. Поэтому уже существующий чужой аккаунт не привязывается даже при совпадении введённых данных.

Если LoginServer успел выполнить INSERT, но соединение завершилось timeout, локальная операция не удаляется. При повторном запросе или ручном восстановлении CMS сначала проверяет фактическую запись и активирует связь без второго INSERT. Для безопасного повтора хранится только зашифрованное драйверное доказательство; открытый пароль не сохраняется и после активации доказательство удаляется. `pending` учитывается в лимите, `failed` — нет. Постоянный фоновый reconcile не запускается.

Страницы персонажей используют только активные связи и обращаются к GameServer с кешированием и коротким cooldown при сбоях. Аватар профиля CMS не смешивается с аватарами персонажей. Восстановление описано в [эксплуатационной инструкции](OPERATIONS.md#создание-игрового-аккаунта-зависло-в-pending-или-завершилось-failed).

## Очередь наград

Сначала KaevCMS хранит награды в собственном веб-инвентаре. Каждое начисление получает серверный UUID операции, ID GameServer и неизменяемый состав предметов в audit trail CMS. Перенос персонажу создаёт отдельный UUID и записывает в `kaev_reward_queue` по одной нейтральной строке на предмет. Уникальность `(request_uuid, line_number)` и request token CMS защищают от повторной передачи и отклоняют повтор токена с другим персонажем или составом.

Статусы CMS: `pending → queued | review | failed` и `review → queued | review | failed`. `queued` подтверждает только наличие полного состава во внешней очереди. Строки обработчика используют `pending → processing → delivered | failed`; для простого или ручного обработчика допустим прямой переход `pending → delivered | failed`. KaevCMS не опрашивает и не перезаписывает эти статусы. Полный контракт и безопасные SQL-шаблоны находятся в `integrations/reward-queue/README.md`, а разбор инцидентов — в [эксплуатационной инструкции](OPERATIONS.md#очередь-наград-в-состоянии-review-или-failed).

Владелец сервера сам выбирает обработчик: Java-плагин, скрипт, SQL-задачу, собственный модуль GameServer или другую интеграцию. KaevCMS не изменяет игровые таблицы `items` и не создаёт игровые object ID.

## Каталог предметов и изображения

`GameItemCatalog` — единый API для Promo Codes, Daily Rewards, Web Inventory и журналов наград. Статические каталоги находятся в `resources/game-items`: `interlude`, `classic`, `high-five` и `shine-maker`.

Каждая запись связывает ID с английским названием из каталога и исходным ключом иконки:

```json
{
  "907": {
    "icon": "accessary_necklace_of_anguish_i00",
    "name_en": "Necklace of Anguish"
  }
}
```

Профиль определяется по хронике GameServer. Значения вроде `Classic 3.5 Tales Untold`, `High Five`, `h5`, `shineMaker` и `Shine Maker` нормализуются к нужному каталогу. При передаче числового ID GameServer CMS читает реальную хронику сервера и не откатывается молча к Interlude.

Ручные названия из `lang/{locale}/items.php`, включая переопределения конкретного сервера, имеют приоритет. При отсутствии русского перевода используется английское название из каталога.

Игровые изображения являются runtime-данными владельца и хранятся в одном каноническом каталоге:

```text
public/uploads/game-assets
```

KaevCMS не включает иконки предметов и изображения персонажей. Содержимое каталога не входит в full, patch, cumulative и shared-hosting архивы и не перезаписывается обновлениями. Рекомендуется WebP; также поддерживаются PNG, JPG и JPEG. Старый каталог `public/game-assets` и разбиение изображений по хроникам больше не используются.

### Иконки предметов

Общие иконки предметов ищутся сначала по числовому ID, затем по ключу иконки из каталога профиля:

```text
public/uploads/game-assets/items/common/{item_id}.webp
public/uploads/game-assets/items/common/{icon_key}.webp
```

Примеры:

```text
public/uploads/game-assets/items/common/57.webp
public/uploads/game-assets/items/common/etc_adena_i00.webp
public/uploads/game-assets/items/common/armor_t45_g_i00.webp
```

Переопределение для конкретного сервера имеет приоритет:

```text
public/uploads/game-assets/items/servers/{server_id}/{item_id}.webp
public/uploads/game-assets/items/servers/{server_id}/{icon_key}.webp
```

Каталоги предметов остаются отдельными для хроник (`interlude`, `classic`, `high-five`, `shine-maker`), но файлы иконок лежат в одном общем пуле.

### Аватары персонажей

Аватары персонажей общие для всех хроник и используют структуру `раса/пол/архетип`:

```text
public/uploads/game-assets/characters/common/{race}/{gender}/{archetype}.webp
```

Пример полного пути:

```text
public/uploads/game-assets/characters/common/human/female/mage.webp
```

Поддерживаемые файлы внутри `characters/common`:

```text
human/male/warrior.webp      human/male/mage.webp
human/female/warrior.webp    human/female/mage.webp
elf/male/warrior.webp        elf/male/mage.webp
elf/female/warrior.webp      elf/female/mage.webp
dark_elf/male/warrior.webp   dark_elf/male/mage.webp
dark_elf/female/warrior.webp dark_elf/female/mage.webp
orc/male/warrior.webp        orc/male/mage.webp
orc/female/warrior.webp      orc/female/mage.webp
dwarf/male/default.webp      dwarf/female/default.webp
kamael/male/default.webp     kamael/female/default.webp
ertheia/male/warrior.webp    ertheia/male/mage.webp
ertheia/female/warrior.webp  ertheia/female/mage.webp
sylph/male/default.webp      sylph/female/default.webp
fallback/male/default.webp   fallback/female/default.webp
fallback/neutral/default.webp
```

Например, нейтральная замена — `characters/common/fallback/neutral/default.webp`. Переопределение для сервера: `characters/servers/{server_id}/{race}/{gender}/{archetype}.webp`. Порядок поиска:

1. Изображение конкретного сервера.
2. Общее изображение из `characters/common`.
3. Встроенная текстовая замена.

Используйте квадратные изображения одинакового размера.

## RUSaCis GameServer

Отдельная схема RUSaCis: персонажи, онлайн, рейтинги по уровню/PvP/PK/времени, активные герои и владельцы замков. Учтены `characters.obj_Id`, `heroes.char_id` и `deletetime IS NULL`. Возврат в город отключён до подтверждения единиц времени `lastAccess` на реальном GameServer. Выдача наград по-прежнему только через нейтральную `kaev_reward_queue`.
