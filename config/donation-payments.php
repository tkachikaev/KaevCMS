<?php

return [
    // Installed payment adapter: activation is controlled only by audited database
    // settings in Donations admin. These static guards are not .env requirements.
    // A trusted deployment can override a guard in code for emergency containment.
    'checkout_enabled' => true,
    'callbacks_enabled' => true,
    'credits_enabled' => true,
];
