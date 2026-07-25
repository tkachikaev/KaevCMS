<?php

namespace App\Rules;

use App\Support\PasswordHashing;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class PasswordWithinHasherLimit implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || PasswordHashing::accepts($value)) {
            return;
        }

        $fail(__('The password must not exceed :bytes bytes while bcrypt is active.', [
            'bytes' => PasswordHashing::BCRYPT_MAX_BYTES,
        ]));
    }
}
