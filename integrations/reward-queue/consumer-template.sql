-- This is intentionally a template, not an automatic item-delivery script.
-- The structure of character inventory tables and object ID allocation differs
-- between GameServer distributions. Review and replace the marked section.

START TRANSACTION;

SELECT *
FROM `kaev_reward_queue`
WHERE `status` = 'pending'
ORDER BY `id`
LIMIT 1
FOR UPDATE;

-- 1. Change the selected row to processing and increment attempts.
-- 2. Validate account_name, character_id, item_id and amount.
-- 3. Add the item using the rules of your GameServer distribution.
-- 4. Change status to delivered, or failed with error_message.

COMMIT;
