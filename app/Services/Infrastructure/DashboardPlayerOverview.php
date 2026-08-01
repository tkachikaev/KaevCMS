<?php

namespace App\Services\Infrastructure;

use App\Contracts\GameServerDatabaseGateway;
use App\Models\Admin;
use App\Models\GameServer;
use App\Models\User;
use App\Models\UserGameAccount;
use App\Support\Modules\ModuleAdminAccessRegistry;
use App\Support\Modules\ModuleNavigationRegistry;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class DashboardPlayerOverview
{
    private const CHARACTER_CACHE_KEY = 'dashboard-player-overview:characters:v1';

    public function __construct(
        private readonly GameServerDatabaseGateway $gameDatabases,
        private readonly ModuleNavigationRegistry $moduleNavigation,
        private readonly ModuleAdminAccessRegistry $moduleAccess,
    ) {}

    /**
     * @return array{
     *     registered_users:int,
     *     game_accounts:int,
     *     characters:int|null,
     *     characters_partial:bool,
     *     support_attention:int|null,
     *     support_route:string|null
     * }
     */
    public function forAdmin(Admin $admin): array
    {
        $support = $this->supportOverview($admin);
        $characters = $this->characters();

        return [
            'registered_users' => User::query()->count(),
            'game_accounts' => UserGameAccount::query()
                ->where('creation_status', UserGameAccount::STATUS_ACTIVE)
                ->count(),
            'characters' => $characters['count'],
            'characters_partial' => $characters['partial'],
            'support_attention' => $support['count'],
            'support_route' => $support['route'],
        ];
    }

    /** @return array{count:int|null,partial:bool} */
    private function characters(): array
    {
        /** @var array{count:int|null,partial:bool} $result */
        $result = Cache::remember(
            self::CHARACTER_CACHE_KEY,
            now()->addSeconds(60),
            fn (): array => $this->collectCharacterCount(),
        );

        return $result;
    }

    /** @return array{count:int|null,partial:bool} */
    private function collectCharacterCount(): array
    {
        $servers = GameServer::query()
            ->with('loginServer')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(static fn (GameServer $server): bool => $server->connectionConfigured());

        if ($servers->isEmpty()) {
            return ['count' => null, 'partial' => false];
        }

        $count = 0;
        $successful = 0;

        foreach ($servers as $server) {
            try {
                $count += (int) $this->gameDatabases->run(
                    $server,
                    static fn (Connection $connection): int => (int) $connection
                        ->table('characters')
                        ->where('accesslevel', 0)
                        ->where('deletetime', 0)
                        ->count(),
                );
                $successful++;
            } catch (Throwable) {
                // A dashboard summary must remain available when one game database is offline.
            }
        }

        return [
            'count' => $successful > 0 ? $count : null,
            'partial' => $successful > 0 && $successful < $servers->count(),
        ];
    }

    /** @return array{count:int|null,route:string|null} */
    private function supportOverview(Admin $admin): array
    {
        foreach ($this->moduleNavigation->availableAdminLinks($admin, $this->moduleAccess) as $link) {
            if ($link['module_id'] !== 'support-tickets') {
                continue;
            }

            return [
                'count' => $link['badge'],
                'route' => $link['route'],
            ];
        }

        return ['count' => null, 'route' => null];
    }
}
