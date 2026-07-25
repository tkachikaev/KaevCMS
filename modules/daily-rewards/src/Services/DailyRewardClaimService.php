<?php

namespace KaevCMS\Modules\DailyRewards\Services;

use App\Models\User;
use App\Models\UserGameAccount;
use App\Services\GameAssets\GameItemCatalog;
use App\Services\Rewards\RewardInventoryService;
use App\Support\Rewards\RewardGrantItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use KaevCMS\Modules\DailyRewards\Exceptions\DailyRewardClaimException;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardCalendar;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardClaim;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardDay;
use KaevCMS\Modules\DailyRewards\Models\DailyRewardItem;

final class DailyRewardClaimService
{
    public function __construct(
        private readonly RewardInventoryService $inventory,
        private readonly GameItemCatalog $items,
    ) {}

    public function claim(
        User $user,
        int $calendarId,
        int $gameAccountId,
        string $requestToken,
    ): DailyRewardClaim {
        $existing = $this->existingClaim($requestToken, $user, $calendarId, $gameAccountId);
        if ($existing instanceof DailyRewardClaim) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($user, $calendarId, $gameAccountId, $requestToken): DailyRewardClaim {
                $existing = DailyRewardClaim::query()
                    ->where('request_token', $requestToken)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof DailyRewardClaim) {
                    return $this->assertClaimOwner($existing, $user, $calendarId, $gameAccountId);
                }

                $calendar = DailyRewardCalendar::query()
                    ->with('gameServer.translations')
                    ->lockForUpdate()
                    ->find($calendarId);

                if (! $calendar instanceof DailyRewardCalendar || ! $calendar->enabled) {
                    throw new DailyRewardClaimException('calendar_unavailable');
                }

                $localNow = CarbonImmutable::now($calendar->runtimeTimezone());
                if ($calendar->year !== $localNow->year || $calendar->month !== $localNow->month) {
                    throw new DailyRewardClaimException('calendar_unavailable');
                }

                $day = DailyRewardDay::query()
                    ->with('items')
                    ->where('calendar_id', $calendar->id)
                    ->where('day_number', $localNow->day)
                    ->lockForUpdate()
                    ->first();

                if (! $day instanceof DailyRewardDay || ! $day->enabled) {
                    throw new DailyRewardClaimException('day_unavailable');
                }

                if ($day->items->isEmpty()) {
                    throw new DailyRewardClaimException('no_rewards');
                }

                $account = UserGameAccount::query()
                    ->where('user_id', $user->id)
                    ->whereNotNull('registration_game_server_id')
                    ->whereHas('registrationGameServer')
                    ->with(['loginServer', 'registrationGameServer'])
                    ->lockForUpdate()
                    ->find($gameAccountId);

                if (! $account instanceof UserGameAccount) {
                    throw new DailyRewardClaimException('account_unavailable');
                }

                if (
                    $calendar->gameServer->login_server_id === null
                    || $account->login_server_id !== $calendar->gameServer->login_server_id
                ) {
                    throw new DailyRewardClaimException('account_unavailable');
                }

                $alreadyClaimed = DailyRewardClaim::query()
                    ->where('calendar_id', $calendar->id)
                    ->where('day_id', $day->id)
                    ->where('user_game_account_id', $account->id)
                    ->lockForUpdate()
                    ->first();

                if ($alreadyClaimed instanceof DailyRewardClaim) {
                    throw new DailyRewardClaimException('already_claimed');
                }

                $snapshot = $day->items
                    ->map(fn (DailyRewardItem $item): array => [
                        'item_id' => (int) $item->item_id,
                        'amount' => (int) $item->amount,
                        'name' => $this->items->knownName($calendar->gameServer, $item->item_id),
                    ])
                    ->values()
                    ->all();

                $claim = DailyRewardClaim::query()->create([
                    'request_token' => $requestToken,
                    'calendar_id' => $calendar->id,
                    'day_id' => $day->id,
                    'user_id' => $user->id,
                    'user_game_account_id' => $account->id,
                    'game_server_id' => $calendar->game_server_id,
                    'reward_inventory_grant_id' => null,
                    'reward_date' => $localNow->toDateString(),
                    'user_email' => $user->email,
                    'game_account_login' => $account->game_login,
                    'items_snapshot' => $snapshot,
                    'claimed_at' => now(),
                ]);

                $grantItems = $day->items
                    ->map(fn (DailyRewardItem $item): RewardGrantItem => new RewardGrantItem(
                        itemId: (int) $item->item_id,
                        amount: (int) $item->amount,
                        name: $this->items->knownName($calendar->gameServer, $item->item_id),
                    ))
                    ->all();

                $grant = $this->inventory->grant(
                    user: $user,
                    server: $calendar->gameServer,
                    grantKey: 'daily-reward.claim.'.$claim->id,
                    sourceType: 'daily-reward',
                    items: $grantItems,
                    sourceReference: (string) $claim->id,
                    sourceLabel: $calendar->periodLabel().' · '.__('module-daily-rewards::messages.day_number', ['day' => $day->day_number]),
                    metadata: [
                        'module' => 'daily-rewards',
                        'calendar_id' => $calendar->id,
                        'day_id' => $day->id,
                        'day_number' => $day->day_number,
                        'user_game_account_id' => $account->id,
                        'game_account_login' => $account->game_login,
                    ],
                    actor: $user,
                );

                $claim->update(['reward_inventory_grant_id' => $grant->id]);

                return $claim->fresh([
                    'calendar.gameServer.translations',
                    'day.items',
                    'gameAccount',
                    'rewardGrant.items',
                ]) ?? $claim;
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->existingClaim($requestToken, $user, $calendarId, $gameAccountId);
            if ($existing instanceof DailyRewardClaim) {
                return $existing;
            }

            $calendar = DailyRewardCalendar::query()->find($calendarId);
            if ($calendar instanceof DailyRewardCalendar) {
                $localNow = CarbonImmutable::now($calendar->runtimeTimezone());
                $dayId = DailyRewardDay::query()
                    ->where('calendar_id', $calendar->id)
                    ->where('day_number', $localNow->day)
                    ->value('id');

                if ($dayId !== null && DailyRewardClaim::query()
                    ->where('calendar_id', $calendar->id)
                    ->where('day_id', $dayId)
                    ->where('user_game_account_id', $gameAccountId)
                    ->exists()) {
                    throw new DailyRewardClaimException('already_claimed');
                }
            }

            throw $exception;
        }
    }

    private function existingClaim(string $requestToken, User $user, int $calendarId, int $gameAccountId): ?DailyRewardClaim
    {
        $claim = DailyRewardClaim::query()
            ->where('request_token', $requestToken)
            ->first();

        return $claim instanceof DailyRewardClaim
            ? $this->assertClaimOwner($claim, $user, $calendarId, $gameAccountId)
            : null;
    }

    private function assertClaimOwner(DailyRewardClaim $claim, User $user, int $calendarId, int $gameAccountId): DailyRewardClaim
    {
        if (
            $claim->user_id !== $user->id
            || $claim->calendar_id !== $calendarId
            || $claim->user_game_account_id !== $gameAccountId
        ) {
            throw new DailyRewardClaimException('invalid');
        }

        return $claim->loadMissing([
            'calendar.gameServer.translations',
            'day.items',
            'gameAccount',
            'rewardGrant.items',
        ]);
    }
}
