<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\GameServer;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Services\GameServerFeatures\CharacterRescueService;
use App\Support\GameServerFeatures\CharacterRescueOutcome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CharacterRescueController extends Controller
{
    public function store(
        Request $request,
        GameServer $gameServer,
        UserGameAccount $gameAccount,
        int $character,
        CharacterRescueService $rescue,
    ): RedirectResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $outcome = $rescue->rescue($user, $gameServer, $gameAccount, $character);

        return redirect()
            ->back()
            ->with('account_operation', $this->operationPayload($outcome));
    }

    /** @return array<string,mixed> */
    private function operationPayload(CharacterRescueOutcome $outcome): array
    {
        if ($outcome->successful) {
            return [
                'type' => 'success',
                'eyebrow' => __('Character rescue'),
                'title' => __('Character returned to the city'),
                'message' => __(':character will appear at :location the next time they enter the game.', [
                    'character' => $outcome->characterName ?? __('Character'),
                    'location' => $outcome->locationName ?? __('configured location'),
                ]),
            ];
        }

        return match ($outcome->code) {
            'online' => $this->warning(__('Character is online'), __('Log out of the game and try again after the configured offline delay.')),
            'offline_delay' => $this->warning(__('Character was recently online'), __('Wait until the minimum offline time has passed, then try again.')),
            'cooldown' => $this->warning(
                __('Character rescue is on cooldown'),
                __('Try again after :time.', ['time' => $outcome->retryAt?->format('d.m.Y H:i') ?? '—']),
            ),
            'disabled' => $this->error(__('Character rescue is disabled'), __('The server owner has disabled this function.')),
            'unsupported' => $this->error(__('Character rescue unavailable'), __('The selected GameServer driver or database schema does not support this function.')),
            'busy' => $this->warning(__('Character rescue is already running'), __('Wait a few seconds and try again.')),
            'database_unavailable' => $this->error(__('GameServer database is unavailable'), __('The character was not changed. Try again later.')),
            'state_changed' => $this->warning(__('Character state changed'), __('The character may have entered the game. Log out and try again.')),
            default => $this->error(__('Character could not be returned'), __('The character was not found in your game account or its state does not allow the operation.')),
        };
    }

    /** @return array<string,string> */
    private function warning(string $title, string $message): array
    {
        return [
            'type' => 'warning',
            'eyebrow' => __('Character rescue'),
            'title' => $title,
            'message' => $message,
        ];
    }

    /** @return array<string,string> */
    private function error(string $title, string $message): array
    {
        return [
            'type' => 'error',
            'eyebrow' => __('Character rescue'),
            'title' => $title,
            'message' => $message,
        ];
    }
}
