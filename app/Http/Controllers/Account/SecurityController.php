<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ChangeAccountPasswordRequest;
use App\Models\User;
use App\Services\Account\AccountPasswordChanger;
use App\Services\Auth\Social\SocialAuthSettings;
use App\Services\RegistrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SecurityController extends Controller
{
    public function __construct(
        private readonly AccountPasswordChanger $passwords,
        private readonly RegistrationSettings $registrationSettings,
    ) {}

    public function edit(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return view('account-theme::security.edit', [
            'user' => $user,
            'passwordPolicy' => $this->registrationSettings->values(),
            'passwordRequirements' => $this->registrationSettings->passwordRequirements(),
            'socialMethods' => collect(['google' => 'Google', 'yandex' => 'Яндекс ID', 'vk' => 'VK ID'])
                ->map(fn (string $name, string $provider): array => [
                    'name' => $name,
                    'available' => app(SocialAuthSettings::class)->isAvailable($provider),
                    'linked' => $user->socialAccounts()->where('provider', $provider)->exists(),
                ])->all(),
        ]);
    }

    public function updatePassword(ChangeAccountPasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        try {
            $this->passwords->change(
                $user,
                (string) $request->validated('current_password'),
                (string) $request->validated('password'),
            );
        } catch (ValidationException $exception) {
            return redirect()
                ->to(public_route('security.edit'))
                ->withErrors($exception->errors());
        }

        $request->session()->regenerate();

        return redirect()
            ->to(public_route('security.edit'))
            ->with('status', __('Your account password has been changed.'));
    }
}
