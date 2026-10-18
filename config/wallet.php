<?php

return [
    // Module integrations are explicitly enabled in trusted PHP code during
    // their installation. Do not expose these flags in arbitrary user input.
    // Manual administrator adjustments use role permissions instead.
    'enabled_sources' => [
        'donations' => true,
        'donation_exchange' => true, // Bundled Donations: user-authorized exchange into Web Inventory only. // Bundled trusted Donations adapter; only verified signed receipts may settle.
        'promo_codes' => false,
        'daily_rewards' => false,
        'shop' => false,
        'shop_refund' => false,
    ],
];
