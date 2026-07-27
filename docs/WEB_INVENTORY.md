# Web inventory / Веб-инвентарь

## English

Rewards remain in the CMS until an idempotent character transfer writes the immutable payload to `kaev_reward_queue`. Reward grants and queue transfers have separate operation UUIDs, retain the selected GameServer ID, and record item IDs and amounts in the audit trail. `queued` means written to the external queue, not delivered by its consumer.

Read the current integration guide: [GAME_INTEGRATION.md](en/GAME_INTEGRATION.md). For `review` and `failed`, use the [operations runbook](en/OPERATIONS.md#reward-queue-review-or-failed).

## Русский

Награды хранятся в CMS до идемпотентной передачи персонажу с записью неизменяемого состава в `kaev_reward_queue`. Начисление и передача имеют отдельные UUID, сохраняют ID выбранного GameServer и состав предметов в audit trail. Статус `queued` означает запись во внешнюю очередь, а не фактическую выдачу её обработчиком.

Актуальная схема: [GAME_INTEGRATION.md](ru/GAME_INTEGRATION.md). Для `review` и `failed` используйте [эксплуатационную инструкцию](ru/OPERATIONS.md#очередь-наград-в-состоянии-review-или-failed).
