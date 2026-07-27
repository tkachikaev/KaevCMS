-- Failed rows and rows left in processing for more than 15 minutes.
-- Review the consumer log before changing any status or delivering an item manually.
SELECT
    `id`,
    `request_uuid`,
    `line_number`,
    `game_server_id`,
    `account_name`,
    `character_id`,
    `character_name`,
    `item_id`,
    `amount`,
    `status`,
    `attempts`,
    `error_message`,
    `created_at`,
    `processing_started_at`,
    `processed_at`
FROM `kaev_reward_queue`
WHERE `status` = 'failed'
   OR (`status` = 'processing' AND `processing_started_at` < UTC_TIMESTAMP() - INTERVAL 15 MINUTE)
ORDER BY `request_uuid`, `line_number`;
