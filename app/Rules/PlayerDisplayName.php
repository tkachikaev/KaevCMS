<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class PlayerDisplayName implements ValidationRule
{
    public static function key(string $name): string
    {
        return hash('sha256', mb_convert_case($name, MB_CASE_FOLD, 'UTF-8'));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/\A[\p{L}\p{Nd}_-]+\z/u', $value) !== 1) {
            $fail(__('Use only letters, numbers, underscores and hyphens.'));

            return;
        }

        $identity = strtr(mb_strtolower(mb_convert_kana($value, 'a', 'UTF-8'), 'UTF-8'), [
            'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'х' => 'x',
            'у' => 'y', 'к' => 'k', 'м' => 'm', 'т' => 't', 'в' => 'b', 'н' => 'h',
            'і' => 'i', 'ј' => 'j', 'ѕ' => 's', 'α' => 'a', 'ο' => 'o', 'ι' => 'i',
            '1' => 'i', '0' => 'o', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't',
        ]);
        $identity = preg_replace('/[_\-\p{Nd}]/u', '', $identity);
        $reserved = ['admin', 'moderator', 'owner', 'support', 'staff', 'system', 'gamemaster', 'gm',
            'админ', 'администратор', 'администрация', 'модератор', 'поддержка', 'система', 'kaevcms'];
        foreach ($reserved as $name) {
            $name = strtr(mb_strtolower($name, 'UTF-8'), [
                'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'х' => 'x',
                'у' => 'y', 'к' => 'k', 'м' => 'm', 'т' => 't', 'в' => 'b', 'н' => 'h',
            ]);
            if (str_starts_with($identity ?? '', $name)) {
                $fail(__('This display name is reserved.'));

                return;
            }
        }
    }
}
