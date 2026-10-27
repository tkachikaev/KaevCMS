<?php

namespace KaevCMS\Modules\Donations\Exceptions;

use InvalidArgumentException;

/** Only this error permits trying a bounded, previously valid retired signing key. */
final class InvalidPaymentSignature extends InvalidArgumentException {}
