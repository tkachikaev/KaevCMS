-- This is intentionally a template, not an automatic item-delivery script.
-- Character inventory columns and object ID allocation differ between GameServer distributions.

START TRANSACTION;

SELECT `id`, `request_uuid`, `line_number`, `account_name`, `character_id`, `item_id`, `amount`
FROM `kaev_reward_queue`
WHERE `status` = 'pending'
ORDER BY `id`
LIMIT 1
FOR UPDATE;

-- Replace :queue_id with the selected id.
UPDATE `kaev_reward_queue`
SET
    `status` = 'processing',
    `attempts` = `attempts` + 1,
    `processing_started_at` = UTC_TIMESTAMP(),
    `error_message` = NULL
WHERE `id` = :queue_id
  AND `status` = 'pending';

-- Continue only when ROW_COUNT() = 1.
-- Validate account_name, character_id, item_id, and amount.
-- Deliver the item using the rules of your GameServer distribution.
-- Finish with delivered only after the item transaction succeeds.
-- Finish with failed when delivery is safely known not to have happened.

COMMIT;
