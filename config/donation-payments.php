<?php

return [
    // C3 deliberately ships with both gates CLOSED. Flip neither on production
    // until completed C4 integration/merchant tests and an explicit release.
    // Callbacks can be accepted without allowing wallet credits, to retain evidence.
    // Real-money form rendering requires ALL four independent trusted gates.
    'checkout_enabled' => false,
    'callbacks_enabled' => false,
    'credits_enabled' => false,
];
