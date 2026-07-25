<?php

namespace App\Services\GameAccounts;

use App\Services\GameAccountSettings;

final class GameAccountCredentialPolicy
{
    public function __construct(private readonly GameAccountSettings $settings) {}

    /** @return list<string> */
    public function loginErrors(string $login): array
    {
        $values = $this->settings->values();
        $errors = [];
        $length = mb_strlen($login);

        if ($length < $values['login_min'] || $length > $values['login_max']) {
            $errors[] = __('The game login must be between :min and :max characters.', [
                'min' => $values['login_min'],
                'max' => $values['login_max'],
            ]);
        }

        if (! preg_match('/\A[A-Za-z0-9]+\z/', $login)) {
            $errors[] = __('The game login may contain only Latin letters and digits.');
        }

        if ($values['login_digit'] && ! preg_match('/[0-9]/', $login)) {
            $errors[] = __('The game login must contain a digit.');
        }

        return $errors;
    }

    /** @return list<string> */
    public function passwordErrors(string $password, ?string $login = null): array
    {
        $values = $this->settings->values();
        $errors = [];
        $length = mb_strlen($password);

        if ($length < $values['password_min'] || $length > $values['password_max']) {
            $errors[] = __('The game password must be between :min and :max characters.', [
                'min' => $values['password_min'],
                'max' => $values['password_max'],
            ]);
        }

        if (! preg_match('/\A[A-Za-z0-9]+\z/', $password)) {
            $errors[] = __('The game password may contain only Latin letters and digits.');
        }

        if ($values['password_lower'] && ! preg_match('/[a-z]/', $password)) {
            $errors[] = __('The game password must contain a lowercase letter.');
        }

        if ($values['password_upper'] && ! preg_match('/[A-Z]/', $password)) {
            $errors[] = __('The game password must contain an uppercase letter.');
        }

        if ($values['password_digit'] && ! preg_match('/[0-9]/', $password)) {
            $errors[] = __('The game password must contain a digit.');
        }

        if ($login !== null && mb_strtolower($password) === mb_strtolower($login)) {
            $errors[] = __('The game password must not match the game login.');
        }

        return $errors;
    }
}
