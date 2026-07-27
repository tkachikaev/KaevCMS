-- Run inside a transaction. Replace :queue_id with a selected pending row.
-- The status guard ensures that only one consumer can claim the row.
UPDATE `kaev_reward_queue`
SET
    `status` = 'processing',
    `attempts` = `attempts` + 1,
    `processing_started_at` = UTC_TIMESTAMP(),
    `processed_at` = NULL,
    `error_message` = NULL
WHERE `id` = :queue_id
  AND `status` = 'pending';

-- Continue only when ROW_COUNT() = 1.
