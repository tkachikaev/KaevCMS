<?php

return [
    'name' => 'KaevCMS',
    'theme' => env('CMS_THEME', 'default'),
    'themes_path' => base_path('themes'),
    'account_theme' => env('CMS_ACCOUNT_THEME', 'luxury'),
    'account_themes_path' => base_path('account-themes'),
    'installed_version_marker' => storage_path('app/kaevcms/installed-version.json'),
    'modules_path' => base_path('modules'),

    'updates' => [
        'maximum_upload_kilobytes' => 524288,
        'maximum_archive_files' => 20000,
        'maximum_uncompressed_bytes' => 1073741824,
    ],

    'modules' => [
        'runtime_retry_seconds' => 60,
        'migration_lock_seconds' => 300,
        'reserved_ids' => [
            'admin',
            'api',
            'core',
            'kaevcms',
            'modules',
            'system',
        ],
    ],

    'news' => [
        'uploads_path' => env('NEWS_UPLOADS_PATH', public_path('uploads')),
    ],

    'pages' => [
        'uploads_path' => env('PAGE_UPLOADS_PATH', public_path('uploads')),
    ],

    'settings' => [
        'uploads_path' => env('SETTINGS_UPLOADS_PATH', public_path('uploads')),
    ],

    'game_assets' => [
        'uploads_path' => public_path('uploads/game-assets'),
    ],

    'account_avatars' => [
        'uploads_path' => public_path('uploads/account-avatars'),
    ],

    'site_defaults' => [
        'name' => env('SITE_NAME', env('APP_NAME', 'KaevCMS')),
        'description' => env('SITE_DESCRIPTION', 'Бесплатная open-source CMS для серверов Lineage II.'),
        'timezone' => env('APP_TIMEZONE', 'Europe/Moscow'),
        'admin_email' => env('SITE_ADMIN_EMAIL', ''),
        'footer_text' => env('SITE_FOOTER_TEXT', '© 2026 KaevCMS'),
        'show_public_online' => (bool) env('SITE_SHOW_PUBLIC_ONLINE', true),
        'translations' => [
            'ru' => [
                'name' => env('SITE_NAME_RU', env('SITE_NAME', env('APP_NAME', 'KaevCMS'))),
                'description' => env('SITE_DESCRIPTION_RU', env('SITE_DESCRIPTION', 'Бесплатная open-source CMS для серверов Lineage II.')),
                'footer_text' => env('SITE_FOOTER_TEXT_RU', env('SITE_FOOTER_TEXT', '© 2026 KaevCMS')),
            ],
            'en' => [
                'name' => env('SITE_NAME_EN', env('SITE_NAME', env('APP_NAME', 'KaevCMS'))),
                'description' => env('SITE_DESCRIPTION_EN', 'Free open-source CMS for Lineage II servers.'),
                'footer_text' => env('SITE_FOOTER_TEXT_EN', env('SITE_FOOTER_TEXT', '© 2026 KaevCMS')),
            ],
        ],
    ],

    'registration' => [
        'enabled' => (bool) env('REGISTRATION_ENABLED', false),
        'email_verification_required' => (bool) env('REGISTRATION_EMAIL_VERIFICATION', true),
    ],

    'public_auth' => [
        'login_ip_per_minute' => 10,
        'login_identity_per_hour' => 20,
        'registration_ip_per_minute' => 5,
        'registration_identity_per_hour' => 5,
        'password_email_ip_per_minute' => 5,
        'password_email_identity_per_hour' => 3,
        'password_reset_ip_per_minute' => 5,
        'password_reset_identity_per_hour' => 5,
    ],

    'admin' => [
        'login_max_attempts' => (int) env('ADMIN_LOGIN_MAX_ATTEMPTS', 5),
        'login_decay_seconds' => (int) env('ADMIN_LOGIN_DECAY_SECONDS', 60),
        'login_ip_max_attempts_per_minute' => (int) env('ADMIN_LOGIN_IP_MAX_ATTEMPTS_PER_MINUTE', 10),
        'login_ip_max_attempts_per_hour' => (int) env('ADMIN_LOGIN_IP_MAX_ATTEMPTS_PER_HOUR', 100),
        'login_log_retention_days' => (int) env('ADMIN_LOGIN_LOG_RETENTION_DAYS', 30),
        'two_factor_max_attempts_per_minute' => (int) env('ADMIN_2FA_MAX_ATTEMPTS_PER_MINUTE', 5),
        'two_factor_max_attempts_per_hour' => (int) env('ADMIN_2FA_MAX_ATTEMPTS_PER_HOUR', 20),
    ],

    'audit' => [
        'retention_days' => (int) env('AUDIT_LOG_RETENTION_DAYS', 90),
    ],

    'queue' => [
        'mail_delivery_retention_days' => 30,
        'failed_job_retention_days' => 90,
        'heartbeat_retention_days' => 30,
    ],

    'external_database' => [
        'connect_timeout_seconds' => (int) env('EXTERNAL_DB_CONNECT_TIMEOUT_SECONDS', 3),
        'query_timeout_ms' => (int) env('EXTERNAL_DB_QUERY_TIMEOUT_MS', 3000),
        'character_limit' => (int) env('EXTERNAL_DB_CHARACTER_LIMIT', 50),
    ],

    'server' => [
        'name' => env('GAME_SERVER_NAME', 'L2Server x1'),
        'chronicle' => env('GAME_CHRONICLE', 'High Five'),
        'rates' => env('GAME_RATES', 'x1'),
        'mode' => env('GAME_MODE', 'PvP'),
        'max_online' => (int) env('GAME_MAX_ONLINE', 5000),
    ],

    'server_monitor' => [
        'refresh_interval_seconds' => (int) env('SERVER_MONITOR_REFRESH_INTERVAL_SECONDS', 60),
        'lock_seconds' => (int) env('SERVER_MONITOR_LOCK_SECONDS', 300),
        'port_timeout_seconds' => (float) env('SERVER_MONITOR_PORT_TIMEOUT_SECONDS', 1.0),
        'failure_threshold' => (int) env('SERVER_MONITOR_FAILURE_THRESHOLD', 3),
        'status_stale_seconds' => (int) env('SERVER_MONITOR_STATUS_STALE_SECONDS', 180),
        'online_stale_seconds' => (int) env('SERVER_MONITOR_ONLINE_STALE_SECONDS', 300),
    ],
];
