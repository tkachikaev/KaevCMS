<?php

return [
    'transfer' => [
        'temporarily_unavailable' => 'Reward transfer is temporarily unavailable. Try again later.',
        'queued' => 'Rewards were transferred.',
        'review' => 'The transfer is being checked. The rewards are protected from duplicate submission.',
        'reward_queue_not_installed' => 'Reward transfer is temporarily unavailable. Try again later.',
        'reward_queue_schema_invalid' => 'Reward transfer is temporarily unavailable. Try again later.',
        'reward_queue_unavailable' => 'Reward transfer is temporarily unavailable. Try again later.',
        'character_not_owned' => 'The selected character does not belong to your account on this server.',
        'invalid_selection' => 'Select between 1 and 50 rewards for one transfer.',
        'request_token_conflict' => 'This transfer request identifier is already used by another operation. Refresh the page and try again.',
        'items_unavailable' => 'One or more selected rewards are unavailable. Refresh the page and try again.',
        'reward_queue_write_failed' => 'The transfer was not completed. The rewards remain available in your web inventory.',
    ],
    'status' => [
        'pending' => [
            'label' => 'Transfer in progress',
            'description' => 'The rewards are reserved while the operation is being completed.',
        ],
        'queued' => [
            'label' => 'Transferred',
            'description' => 'The rewards were transferred for further processing.',
        ],
        'review' => [
            'label' => 'Needs review',
            'description' => 'The write result is uncertain or conflicts with an existing payload. Rewards remain reserved.',
        ],
        'failed' => [
            'label' => 'Failed safely',
            'description' => 'The transfer was not completed, so the rewards were returned to the web inventory.',
        ],
        'unknown' => [
            'label' => 'Unknown',
        ],
    ],
    'queue' => [
        'journal' => [
            'title' => 'Reward queue',
            'description' => 'Audit journal for reward transfers from KaevCMS to GameServer queues.',
            'boundary' => 'KaevCMS confirms only that a complete payload reached kaev_reward_queue. Item delivery is controlled by the GameServer administrator or an external consumer.',
            'no_operations_title' => 'No reward queue transfers yet',
            'no_operations_description' => 'Operations will appear here after players send web inventory rewards to a GameServer queue.',
            'operation_uuid' => 'Operation UUID',
            'server' => 'Server',
            'player' => 'Player',
            'character' => 'Character',
            'rewards' => 'Rewards',
            'status' => 'Status',
            'actions' => 'Actions',
            'date' => 'Date and time',
            'check_again' => 'Check again',
            'diagnostic' => 'Diagnostic',
            'recommended_action' => 'Recommended action',
            'queue_ready' => 'The selected GameServer queue is available and has the supported schema.',
            'queue_check_title' => 'Selected GameServer queue check',
            'all_servers_hint' => 'Select one GameServer to run a live queue availability check.',
            'total' => 'Total operations',
        ],
        'reconcile' => [
            'queued' => 'The complete reward payload was confirmed in the GameServer queue.',
            'failed' => 'No matching queue operation was found. Reserved rewards were returned to the web inventory.',
            'review' => 'The result remains uncertain. Rewards stay reserved to prevent duplicate delivery.',
        ],
        'diagnostics' => [
            'reward_queue_not_installed' => [
                'message' => 'The kaev_reward_queue table is missing from the selected GameServer database.',
                'action' => 'Run integrations/reward-queue/install.sql in that GameServer database, then check again.',
            ],
            'reward_queue_schema_invalid' => [
                'message' => 'The kaev_reward_queue table exists but its required columns do not match the KaevCMS contract.',
                'action' => 'Compare the table with integrations/reward-queue/install.sql. Preserve existing rows before changing the schema.',
            ],
            'reward_queue_unavailable' => [
                'message' => 'KaevCMS could not connect to or inspect the selected GameServer database.',
                'action' => 'Restore database connectivity and credentials, then use Check again. Do not manually deliver reserved rewards while the result is uncertain.',
            ],
            'empty_reward_queue_payload' => [
                'message' => 'The prepared reward payload was empty or invalid and was not written.',
                'action' => 'Review the CMS operation and item values. The rewards can be retried after the invalid source data is corrected.',
            ],
            'reward_queue_payload_conflict' => [
                'message' => 'Rows with this operation UUID already exist, but their immutable payload differs from the CMS operation.',
                'action' => 'Do not deliver or delete either side blindly. Compare request_uuid, line_number, target character, item IDs, and amounts using the operator runbook.',
            ],
            'reward_queue_write_failed' => [
                'message' => 'KaevCMS reconnected and confirmed that no matching queue rows were created.',
                'action' => 'The rewards were returned to the web inventory. The player may start a new transfer after the queue problem is fixed.',
            ],
            'reward_queue_write_unknown' => [
                'message' => 'The database response was lost and KaevCMS could not confirm whether the queue rows were committed.',
                'action' => 'Restore connectivity and use Check again. Keep the rewards reserved until the operation UUID is verified.',
            ],
            'legacy_bridge_operation_requires_review' => [
                'message' => 'This operation originated from the removed legacy delivery bridge and has no reliable modern write result.',
                'action' => 'Inspect the GameServer database and the historical update logs before changing the reserved rewards.',
            ],
            'unknown' => [
                'message' => 'The operation has an unrecognized diagnostic code.',
                'action' => 'Preserve the operation and review the application log and audit trail before taking action.',
            ],
        ],
    ],
];
