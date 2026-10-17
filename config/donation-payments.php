<?php

return [
    // C3 deliberately ships with both gates CLOSED. Flip neither on production
    // until completed C4 integration/merchant tests and an explicit release.
    // Callbacks can be accepted without allowing wallet credits, to retain evidence.
    'callbacks_enabled' => false,
    'credits_enabled' => false,
];
