<?php

namespace App\Livewire\Account;

use App\Models\User;
use App\Models\UserGameAccount;
use App\Services\GameAccounts\GameAccountCredentialPolicy;
use App\Services\GameAccounts\GameAccountPasswordChanger;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class GameAccountPasswordForm extends Component
{
    #[Locked]
    public int $accountId;

    public string $currentPassword = '';

    public string $gamePassword = '';

    public string $gamePasswordConfirmation = '';

    public ?string $status = null;

    public function mount(int $accountId): void
    {
        $this->accountId = $accountId;
        $this->account();
    }

    public function save(): void
    {
        $user = $this->user();
        $account = $this->account();
        $this->status = null;
        $this->resetValidation();

        $rateLimitKey = 'game-account-password:'.$user->getAuthIdentifier();
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $this->addError('gamePassword', __('Too many password change attempts. Try again later.'));

            return;
        }

        $this->withValidator(function (Validator $validator): void {
            $validator->after(function (Validator $validator): void {
                foreach (app(GameAccountCredentialPolicy::class)->passwordErrors($this->gamePassword) as $error) {
                    $validator->errors()->add('gamePassword', $error);
                }
            });
        })->validate([
            'currentPassword' => ['required', 'string'],
            'gamePassword' => ['required', 'string'],
            'gamePasswordConfirmation' => ['required', 'string', 'same:gamePassword'],
        ], [], [
            'currentPassword' => __('Current personal account password'),
            'gamePassword' => __('New game password'),
            'gamePasswordConfirmation' => __('Repeat game password'),
        ]);

        RateLimiter::hit($rateLimitKey, 3600);

        try {
            app(GameAccountPasswordChanger::class)->change(
                $user,
                $account,
                $this->currentPassword,
                $this->gamePassword,
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $property = match ($field) {
                    'current_password' => 'currentPassword',
                    'game_password' => 'gamePassword',
                    default => $field,
                };

                foreach ($messages as $message) {
                    $this->addError($property, $message);
                }
            }

            return;
        }

        $this->reset(['currentPassword', 'gamePassword', 'gamePasswordConfirmation']);
        $this->status = __('Game account password changed.');
    }

    public function render(): View
    {
        return view('account-theme::livewire.game-account-password-form');
    }

    private function account(): UserGameAccount
    {
        return $this->user()
            ->availableGameAccounts()
            ->with('loginServer')
            ->findOrFail($this->accountId);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
