<?php

return [
    'admin' => [
        'email' => env('BROWSER_TEST_ADMIN_EMAIL', 'browser-admin@example.test'),
        'password' => env('BROWSER_TEST_ADMIN_PASSWORD', 'BrowserPassword123!'),
    ],
    'read_only' => [
        'email' => env('BROWSER_TEST_READ_ONLY_EMAIL', 'browser-viewer@example.test'),
        'password' => env('BROWSER_TEST_READ_ONLY_PASSWORD', 'BrowserViewerPassword123!'),
    ],
    'player' => [
        'email' => env('BROWSER_TEST_PLAYER_EMAIL', 'browser-player@example.test'),
        'password' => env('BROWSER_TEST_PLAYER_PASSWORD', 'BrowserPlayerPassword123!'),
    ],
];
