<?php

return [
    // Module integrations are explicitly enabled in trusted PHP code during
    // their installation. Do not expose these flags in arbitrary user input.
    // Manual administrator adjustments use role permissions instead.
    'enabled_sources' => [
        'donations' => false,
        'promo_codes' => false,
        'daily_rewards' => false,
        'shop' => false,
        'shop_refund' => false,
    ],
];
