<?php

return [
    'admin' => [
        'email' => env('BROWSER_TEST_ADMIN_EMAIL', 'browser-admin@example.test'),
        'password' => env('BROWSER_TEST_ADMIN_PASSWORD', 'BrowserPassword123!'),
    ],
    'auditor' => [
        'email' => env('BROWSER_TEST_AUDITOR_EMAIL', 'browser-auditor@example.test'),
        'password' => env('BROWSER_TEST_AUDITOR_PASSWORD', 'BrowserAuditorPassword123!'),
    ],
    'player' => [
        'email' => env('BROWSER_TEST_PLAYER_EMAIL', 'browser-player@example.test'),
        'password' => env('BROWSER_TEST_PLAYER_PASSWORD', 'BrowserPlayerPassword123!'),
    ],
];
