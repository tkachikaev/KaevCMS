<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ChangeGameAccountPasswordRequest;
use App\Models\User;
use App\Services\GameAccounts\GameAccountPasswordChanger;
use Illuminate\Http\RedirectResponse;

class GameAccountPasswordController extends Controller
{
    public function __construct(private readonly GameAccountPasswordChanger $passwordChanger) {}

    public function update(ChangeGameAccountPasswordRequest $request): RedirectResponse
    {
        $gameAccount = $this->gameAccountId($request);
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $account = $user->availableGameAccounts()->with('loginServer')->findOrFail($gameAccount);
        $this->passwordChanger->change(
            $user,
            $account,
            (string) $request->validated('current_password'),
            (string) $request->validated('game_password'),
        );

        return back()->with('status', __('Game account password changed.'));
    }

    private function gameAccountId(ChangeGameAccountPasswordRequest $request): int
    {
        $value = $request->route('gameAccount');

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        abort(404);
    }
}
