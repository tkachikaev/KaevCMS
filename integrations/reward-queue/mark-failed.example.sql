-- Replace :queue_id and :error_message before execution.
UPDATE `kaev_reward_queue`
SET
    `status` = 'failed',
    `processed_at` = UTC_TIMESTAMP(),
    `error_message` = :error_message
WHERE `id` = :queue_id
  AND `status` IN ('pending', 'processing');
