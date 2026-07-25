SELECT
    `id`,
    `request_uuid`,
    `line_number`,
    `game_server_id`,
    `source`,
    `cms_user_id`,
    `account_name`,
    `character_id`,
    `character_name`,
    `item_id`,
    `amount`,
    `status`,
    `attempts`,
    `created_at`
FROM `kaev_reward_queue`
WHERE `status` = 'pending'
ORDER BY `id`;
