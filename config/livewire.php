<?php

return [
    'class_namespace' => 'App\\Livewire',
    'view_path' => resource_path('views/livewire'),
    'inject_assets' => false,
    'csp_safe' => true,
    'navigate' => [
        'show_progress_bar' => true,
        'progress_bar_color' => '#2563eb',
    ],
    'payload' => [
        'max_size' => 1024 * 1024,
        'max_nesting_depth' => 10,
        'max_calls' => 50,
        'max_components' => 20,
    ],
];
