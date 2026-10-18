<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Auth\AdminRole;
use App\Contracts\WalletGateway;
use App\Models\Admin;
use App\Models\GameServer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Rewards\RewardInventoryService;
use App\Support\Rewards\RewardGrantItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Donation coin conversion into an ordinary item in Web Inventory; no game DB writes. */
final class DonationGameExchange
{
    public const MAX_COINS = 1000000;

    public const MAX_GAME_ITEMS = 2000000000;

    public function __construct(
        private readonly WalletGateway $wallet,
        private readonly RewardInventoryService $inventory,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<array{server_id:int,server_name:string,item_id:int,items_per_coin:int}> */
    public function available(): array
    {
        try {
            if (! Schema::hasTable('module_donation_game_exchange_rates')) {
                return [];
            }
        } catch (Throwable) {
            return []; // Incomplete installation must not break module bootstrapping.
        }

        return DB::table('module_donation_game_exchange_rates as r')
            ->join('game_servers as s', 's.id', '=', 'r.game_server_id')
            ->where('r.enabled', true)->whereNotNull('r.item_id')->whereNotNull('r.items_per_coin')
            ->orderBy('s.sort_order')->orderBy('s.id')
            ->get(['s.id as server_id', 's.name as server_name', 'r.item_id', 'r.items_per_coin'])
            ->filter(static fn (object $row): bool => (int) $row->item_id > 0 && (int) $row->item_id <= 2147483647
                && (int) $row->items_per_coin > 0 && (int) $row->items_per_coin <= self::MAX_GAME_ITEMS)
            ->map(static fn (object $row): array => [
                'server_id' => (int) $row->server_id,
                'server_name' => (string) $row->server_name,
                'item_id' => (int) $row->item_id,
                'items_per_coin' => (int) $row->items_per_coin,
            ])->values()->all();
    }

    /** @return list<array{server_id:int,server_name:string,item_id:int|null,items_per_coin:int|null,enabled:bool}> */
    public function configured(): array
    {
        $rows = Schema::hasTable('module_donation_game_exchange_rates')
            ? DB::table('module_donation_game_exchange_rates')->get()->keyBy('game_server_id')
            : collect();

        return GameServer::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'name'])->map(
            static function (GameServer $server) use ($rows): array {
                $row = $rows->get($server->id);

                return [
                    'server_id' => (int) $server->id,
                    'server_name' => (string) $server->name,
                    'item_id' => $row?->item_id === null ? null : (int) $row->item_id,
                    'items_per_coin' => $row?->items_per_coin === null ? null : (int) $row->items_per_coin,
                    'enabled' => $row !== null && (bool) $row->enabled,
                ];
            }
        )->all();
    }

    public function configure(Admin $admin, int $serverId, ?int $itemId, ?int $itemsPerCoin, bool $enabled): void
    {
        $current = Auth::guard('admin')->user();
        if (! $admin->exists || ! $admin->is_active || ! $current instanceof Admin
            || (int) $current->getKey() !== (int) $admin->getKey()
            || ! in_array($admin->role, [AdminRole::Owner, AdminRole::Administrator], true)) {
            throw new AuthorizationException;
        }
        if ($enabled && ($itemId === null || $itemsPerCoin === null)) {
            throw new InvalidArgumentException('Enabled exchange requires an item ID and a positive rate.');
        }
        if (($itemId !== null && ($itemId < 1 || $itemId > 2147483647))
            || ($itemsPerCoin !== null && ($itemsPerCoin < 1 || $itemsPerCoin > 1000000))) {
            throw new InvalidArgumentException('Invalid exchange item or rate.');
        }
        DB::transaction(function () use ($admin, $serverId, $itemId, $itemsPerCoin, $enabled): void {
            GameServer::query()->whereKey($serverId)->lockForUpdate()->firstOrFail();
            $before = DB::table('module_donation_game_exchange_rates')
                ->where('game_server_id', $serverId)->lockForUpdate()->first();
            $now = now();
            $values = [
                'item_id' => $itemId,
                'items_per_coin' => $itemsPerCoin,
                'enabled' => $enabled,
                'updated_by_admin_id' => $admin->getKey(),
                'updated_at' => $now,
            ];
            if ($before === null) {
                DB::table('module_donation_game_exchange_rates')->insert($values + [
                    'game_server_id' => $serverId, 'created_at' => $now,
                ]);
            } else {
                DB::table('module_donation_game_exchange_rates')->where('game_server_id', $serverId)->update($values);
            }
            if ($this->audit->success('module', 'donations.exchange_configured', $admin,
                'donations-exchange:'.$serverId, [
                    'game_server_id' => $serverId,
                    'before' => $before === null ? null : [
                        'item_id' => $before->item_id, 'items_per_coin' => $before->items_per_coin, 'enabled' => (bool) $before->enabled,
                    ],
                    'after' => ['item_id' => $itemId, 'items_per_coin' => $itemsPerCoin, 'enabled' => $enabled],
                ]) === null) {
                throw new RuntimeException('Unable to audit exchange settings.');
            }
        }, 3);
    }

    /** @return array{server_id:int,coins:int,item_id:int,items_per_coin:int,game_amount:int} */
    public function exchange(User $user, int $serverId, int $coins, string $requestUuid): array
    {
        $authenticated = Auth::guard('web')->user();
        if (! $user->exists || ! $authenticated instanceof User || ! $authenticated->is_active
            || (int) $authenticated->getKey() !== (int) $user->getKey()) {
            throw new AuthorizationException;
        }
        if ($coins < 1 || $coins > self::MAX_COINS
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD', $requestUuid) !== 1) {
            throw new InvalidArgumentException('Invalid exchange request.');
        }

        return DB::transaction(function () use ($user, $serverId, $coins, $requestUuid): array {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $existing = DB::table('module_donation_game_exchanges')->where('request_uuid', $requestUuid)->first();
            if ($existing !== null) {
                if ((int) $existing->user_id !== (int) $user->getKey()
                    || (int) $existing->game_server_id !== $serverId || (int) $existing->coins !== $coins) {
                    throw new InvalidArgumentException('Exchange request UUID has already been used for different terms.');
                }

                return $this->result($existing);
            }
            $rate = DB::table('module_donation_game_exchange_rates')
                ->where('game_server_id', $serverId)->lockForUpdate()->first();
            if ($rate === null || ! (bool) $rate->enabled || (int) $rate->item_id < 1
                || (int) $rate->items_per_coin < 1 || (int) $rate->items_per_coin > 1000000
                || $coins > intdiv(self::MAX_GAME_ITEMS, (int) $rate->items_per_coin)) {
                throw new InvalidArgumentException('Exchange is disabled or amount is outside the allowed limits.');
            }
            $server = GameServer::query()->findOrFail($serverId);
            $itemId = (int) $rate->item_id;
            $factor = (int) $rate->items_per_coin;
            $gameAmount = $coins * $factor;
            $reference = 'Game coin exchange '.$requestUuid;
            $debit = $this->wallet->debitForDonationExchange($user, $coins, 'donation-exchange:'.$requestUuid, $reference);
            $grant = $this->inventory->grant(
                $user, $server, 'donation-exchange:'.$requestUuid, 'donation_exchange',
                [new RewardGrantItem($itemId, $gameAmount)], $requestUuid,
                'Donation coin exchange', [
                    'coins' => $coins, 'items_per_coin' => $factor,
                ], $user,
            );
            DB::table('module_donation_game_exchanges')->insert([
                'request_uuid' => $requestUuid,
                'user_id' => $user->getKey(), 'game_server_id' => $serverId,
                'coins' => $coins, 'item_id' => $itemId, 'items_per_coin' => $factor,
                'game_amount' => $gameAmount,
                'wallet_entry_id' => $debit->getKey(),
                'reward_inventory_grant_id' => $grant->getKey(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($this->audit->success('module', 'donations.coins_exchanged', $user,
                'donations-exchange:'.$requestUuid, [
                    'user_id' => $user->getKey(), 'game_server_id' => $serverId,
                    'coins' => $coins, 'game_amount' => $gameAmount,
                    'item_id' => $itemId, 'grant_id' => $grant->getKey(),
                ]) === null) {
                throw new RuntimeException('Unable to audit coin exchange.');
            }

            return [
                'server_id' => $serverId, 'coins' => $coins, 'item_id' => $itemId,
                'items_per_coin' => $factor, 'game_amount' => $gameAmount,
            ];
        }, 3);
    }

    /** @return array{server_id:int,coins:int,item_id:int,items_per_coin:int,game_amount:int} */
    private function result(object $row): array
    {
        return [
            'server_id' => (int) $row->game_server_id,
            'coins' => (int) $row->coins, 'item_id' => (int) $row->item_id,
            'items_per_coin' => (int) $row->items_per_coin, 'game_amount' => (int) $row->game_amount,
        ];
    }
}
