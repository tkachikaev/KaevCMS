<?php

namespace App\Http\Requests\Account;

use App\Services\GameAccounts\GameAccountCredentialPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateGameAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'game_server_id' => ['required', 'integer', 'exists:game_servers,id'],
            'game_login' => ['required', 'string'],
            'game_password' => ['required', 'string'],
            'game_password_confirmation' => ['required', 'string', 'same:game_password'],
        ];
    }

    /** @return array<string,string> */
    public function attributes(): array
    {
        return [
            'game_server_id' => __('Game server'),
            'game_login' => __('Game login'),
            'game_password' => __('Game password'),
            'game_password_confirmation' => __('Repeat game password'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['game_login' => trim((string) $this->input('game_login'))]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $policy = app(GameAccountCredentialPolicy::class);
            $login = (string) $this->input('game_login');
            $password = (string) $this->input('game_password');

            foreach ($policy->loginErrors($login) as $error) {
                $validator->errors()->add('game_login', $error);
            }

            foreach ($policy->passwordErrors($password, $login) as $error) {
                $validator->errors()->add('game_password', $error);
            }
        });
    }
}
