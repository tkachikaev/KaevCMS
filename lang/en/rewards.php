<?php

return [
    'transfer' => [
        'queued' => 'Rewards were transferred to the GameServer queue.',
        'review' => 'The queue write result is uncertain. The rewards are locked for administrator review.',
        'reward_queue_not_installed' => 'The kaev_reward_queue table is not installed in this GameServer database.',
        'reward_queue_schema_invalid' => 'The kaev_reward_queue table has an unsupported structure.',
        'reward_queue_unavailable' => 'The GameServer reward queue is unavailable. Check the database connection.',
        'character_not_owned' => 'The selected character does not belong to your account on this server.',
        'invalid_selection' => 'Select between 1 and 50 rewards for one transfer.',
        'items_unavailable' => 'One or more selected rewards are unavailable. Refresh the page and try again.',
        'reward_queue_write_failed' => 'The reward could not be written to the GameServer queue. It remains available in your web inventory.',
    ],
];
