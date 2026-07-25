-- Replace :queue_id with the processed kaev_reward_queue.id value.
-- Run this only after your own GameServer-side or database-side consumer
-- has actually delivered the item.
UPDATE `kaev_reward_queue`
SET
    `status` = 'delivered',
    `processed_at` = UTC_TIMESTAMP(),
    `error_message` = NULL
WHERE `id` = :queue_id
  AND `status` IN ('pending', 'processing');
