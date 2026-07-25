<?php

return [
    'default' => env('APP_LOCALE', 'ru'),
    'fallback' => env('APP_FALLBACK_LOCALE', 'en'),
    'built_in' => ['ru', 'en'],
    'locale_pattern' => '[a-z]{2,3}(?:-[A-Z]{2})?',
];
