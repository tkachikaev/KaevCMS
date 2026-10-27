<?php

namespace KaevCMS\Modules\Donations\Exceptions;

use RuntimeException;

/** Sanitized classification only. Never attach PayPal response bodies or credentials. */
final class PayPalApiException extends RuntimeException
{
    public function __construct(
        public readonly string $diagnosticCode,
        public readonly bool $uncertain,
    ) {
        parent::__construct('PayPal API operation failed ('.$diagnosticCode.').');
    }
}
