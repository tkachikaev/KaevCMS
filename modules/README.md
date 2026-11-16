# KaevCMS modules / Модули KaevCMS

## English

Modules are trusted PHP packages installed under `modules/<id>`. They use a strict manifest, scoped routes, views, translations, navigation entries, and immutable migrations. Only the owner may change module state or approve a new version. Modules are not sandboxed.

The bundled `donations` module (B1) provides secured configuration and an optional read-only coin funding landing; it does not take payments yet. The bundled `promo-codes` module grants server-bound rewards through the core web inventory. The bundled `daily-rewards` module provides monthly, server-specific reward calendars with one claim per eligible game account and immutable claim history. The bundled `support-tickets` module provides private player tickets, staff replies, internal notes, assignment, revision history and personal administrator notifications. The bundled `news-comments` module supports discussions beneath published news with configurable approval, edits, per-user limits and optional Editor moderation.

See `docs/en/MODULES.md`.

## Русский

Модули являются доверенными PHP-пакетами в `modules/<id>`. Они используют строгий manifest, scoped routes, views, переводы, навигацию и неизменяемые миграции. Только владелец меняет состояние модуля и подтверждает новую версию. Sandbox отсутствует.

Встроенный `donations` (B1) добавляет отдельные настройки и необязательную информационную страницу пополнения; реальные платежи пока недоступны. Встроенный `promo-codes` выдаёт привязанные к GameServer награды через основной веб-инвентарь. Встроенный `daily-rewards` добавляет месячные календари с одной выдачей на подходящий игровой аккаунт и неизменяемой историей получений. Встроенный `support-tickets` добавляет приватные обращения игроков, ответы сотрудников, внутренние заметки, назначение ответственного, историю изменений сообщений и персональные уведомления администраторов. Встроенный `news-comments` поддерживает комментарии к новостям с режимом модерации, редактированием, лимитами сообщений и настраиваемыми правами редактора.

См. `docs/ru/MODULES.md`.

## Module artwork

A module may provide an administrator catalog image at `assets/module.webp` inside its own directory. KaevCMS discovers it automatically; no manifest field is required. The file must be a square 320×320 / 512×512 WebP image and no larger than 2 MB. If the file is missing or invalid, the module catalog uses the standard letter placeholder.

The bundled `shop` module (iteration 1) contains disabled sales settings and a read-only administration overview. It has no checkout or player interface yet. / Встроенный `shop` (итерация 1) содержит отключённые настройки продаж и административную страницу; покупок пока нет.
