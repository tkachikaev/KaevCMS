<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateAccountAvatarRequest;
use App\Http\Requests\Account\UpdateDisplayNameRequest;
use App\Models\User;
use App\Services\Account\AccountAvatarCatalog;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function __construct(
        private readonly AccountAvatarCatalog $avatars,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function edit(Request $request): View
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return view('account-theme::profile.edit', [
            'user' => $user,
            'linkedSocialProviders' => array_intersect_key([
                'google' => 'Google', 'yandex' => 'Яндекс ID', 'vk' => 'VK ID',
                'telegram' => 'Telegram', 'discord' => 'Discord', 'facebook' => 'Facebook',
            ], array_fill_keys($user->socialAccounts()->pluck('provider')->all(), true)),
        ]);
    }

    public function updateDisplayName(UpdateDisplayNameRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        try {
            DB::transaction(function () use ($user, $request): void {
                $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                $name = $request->validated('display_name');
                $name = is_string($name) && $name !== '' ? $name : null;
                if ($locked->getAttribute('display_name') === $name) {
                    return;
                }
                $locked->forceFill(['display_name' => $name])->save();
                if ($this->auditLogger->success(category: 'user', action: 'user.display_name_updated',
                    actor: $locked, target: $locked, details: ['display_name_set' => $name !== null]) === null) {
                    throw new \RuntimeException('Unable to audit profile update.');
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['display_name' => __('This display name is already taken.')]);
        }

        return redirect()->to(public_route('profile.edit'))->with('status', __('Display name saved.'));
    }

    public function updateAvatar(UpdateAccountAvatarRequest $request): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $previous = $user->avatar_filename;
        $filename = $request->validated('avatar_filename');
        $selected = is_string($filename) && $filename !== '' ? $filename : null;

        if ($selected !== null && ! $this->avatars->contains($selected)) {
            return back()->withErrors([
                'avatar_filename' => __('The selected avatar is no longer available.'),
            ]);
        }

        $user->forceFill(['avatar_filename' => $selected])->save();

        if ($previous !== $selected) {
            $this->auditLogger->success(
                category: 'user',
                action: 'user.account_avatar_updated',
                actor: $user,
                target: $user,
                details: [
                    'previous_avatar' => $previous,
                    'selected_avatar' => $selected,
                ],
            );
        }

        return redirect()->to($this->safeReturnPath($request->validated('return_to')))
            ->with('status', __('Avatar saved.'));
    }

    private function safeReturnPath(mixed $value): string
    {
        if (! is_string($value)) {
            return public_route('account');
        }

        $decoded = rawurldecode($value);
        if (! str_starts_with($decoded, '/')
            || str_starts_with($decoded, '//')
            || str_contains($decoded, "\0")
            || str_contains($decoded, "\r")
            || str_contains($decoded, "\n")
            || str_contains($decoded, '\\')) {
            return public_route('account');
        }

        $parts = parse_url($decoded);
        if (! is_array($parts)) {
            return public_route('account');
        }

        $path = $parts['path'] ?? null;
        if (! is_string($path)
            || isset($parts['scheme'])
            || isset($parts['host'])
            || preg_match('#^/(?:[^/]+/)?account(?:/|$)#', $path) !== 1) {
            return public_route('account');
        }

        return $value;
    }
}
