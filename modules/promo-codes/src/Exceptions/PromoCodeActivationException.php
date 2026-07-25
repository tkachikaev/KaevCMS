<?php

namespace KaevCMS\Modules\PromoCodes\Exceptions;

use RuntimeException;

final class PromoCodeActivationException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct($reasonCode);
    }
}
