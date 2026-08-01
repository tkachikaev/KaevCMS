# KaevCMS modules / Модули KaevCMS

## English

Modules are trusted PHP packages installed under `modules/<id>`. They use a strict manifest, scoped routes, views, translations, navigation entries, and immutable migrations. Only the owner may change module state or approve a new version. Modules are not sandboxed.

The bundled `promo-codes` module grants server-bound rewards through the core web inventory. The bundled `daily-rewards` module provides monthly, server-specific reward calendars with one claim per eligible game account and immutable claim history. The bundled `support-tickets` module provides private player tickets, staff replies, internal notes, assignment, revision history and personal administrator notifications.

See `docs/en/MODULES.md`.

## Русский

Модули являются доверенными PHP-пакетами в `modules/<id>`. Они используют строгий manifest, scoped routes, views, переводы, навигацию и неизменяемые миграции. Только владелец меняет состояние модуля и подтверждает новую версию. Sandbox отсутствует.

Встроенный `promo-codes` выдаёт привязанные к GameServer награды через основной веб-инвентарь. Встроенный `daily-rewards` добавляет месячные календари с одной выдачей на подходящий игровой аккаунт и неизменяемой историей получений. Встроенный `support-tickets` добавляет приватные обращения игроков, ответы сотрудников, внутренние заметки, назначение ответственного, историю изменений сообщений и персональные уведомления администраторов.

См. `docs/ru/MODULES.md`.

## Module artwork

A module may provide an administrator catalog image at `assets/module.webp` inside its own directory. KaevCMS discovers it automatically; no manifest field is required. The file must be a square 512×512 WebP image and no larger than 2 MB. If the file is missing or invalid, the module catalog uses the standard letter placeholder.
