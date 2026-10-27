<?php

// Larastan validates view-string against the booted view finder. Bundled module
// views must be known even when a module is disabled or awaiting an update in
// the developer's database. Register templates only, without executing modules.
foreach (['daily-rewards', 'donations', 'promo-codes', 'support-tickets'] as $moduleId) {
    view()->addNamespace('module-'.$moduleId, __DIR__.'/modules/'.$moduleId.'/resources/views');
}
