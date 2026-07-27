<?php

namespace KaevCMS\Modules\PromoCodes\Exceptions;

use RuntimeException;

final class PromoCodeActivationException extends RuntimeException
{
    public function __construct(public readonly PromoCodeActivationFailure $failure)
    {
        parent::__construct($failure->value);
    }

    public function reasonCode(): string
    {
        return $this->failure->value;
    }

    public function translationKey(): string
    {
        return $this->failure->translationKey();
    }
}
