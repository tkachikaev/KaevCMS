<?php

namespace KaevCMS\Modules\Voting\Services;

use App\Models\GameServer;
use App\Models\User;
use App\Models\UserGameAccount;
use Illuminate\Database\Eloquent\Collection;
use KaevCMS\Modules\Voting\Models\VotingServerReward;
use KaevCMS\Modules\Voting\Models\VotingSetting;
use KaevCMS\Modules\Voting\Models\VotingVote;

final class VotingAvailability
{
    public function enabled(?VotingSetting $setting = null): bool
    {
        $setting ??= VotingSetting::query()->where('provider_code', 'ktreon')->first();

        return $setting !== null && $setting->enabled && $setting->player_access_enabled
            && $setting->project_id !== null && $setting->api_key_ciphertext !== null;
    }

    /** @return Collection<int, VotingServerReward> */
    public function rewards(): Collection
    {
        return VotingServerReward::query()->with('gameServer.translations')->where('enabled', true)
            ->whereHas('gameServer', static fn ($query) => $query->whereNotNull('login_server_id'))
            ->orderBy('game_server_id')->get()
            ->filter(static fn (VotingServerReward $reward): bool => $reward->configuredItems() !== [])->values();
    }

    /** @return Collection<int, UserGameAccount> */
    public function accounts(User $user, GameServer $server): Collection
    {
        if ($server->login_server_id === null) {
            return new Collection;
        }

        return $user->availableGameAccounts()->where('login_server_id', $server->login_server_id)
            ->orderBy('game_login')->orderBy('id')->get();
    }

    public function hasHistory(User $user): bool
    {
        return VotingVote::query()->where('user_id', $user->id)->exists();
    }

    public function visible(): bool
    {
        if ($this->enabled() && $this->rewards()->isNotEmpty()) {
            return true;
        }

        $user = auth('web')->user();

        return $user instanceof User && $this->hasHistory($user);
    }
}
