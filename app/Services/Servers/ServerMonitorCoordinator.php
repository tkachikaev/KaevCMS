<?php

namespace App\Services\Servers;

use App\Models\GameServer;
use App\Models\LoginServer;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ServerMonitorCoordinator
{
    public function __construct(
        private readonly ServerMonitor $monitor,
        private readonly ServerMonitorSettings $settings,
    ) {}

    public function isDue(): bool
    {
        $cutoffTimestamp = now()->getTimestamp() - $this->refreshIntervalSeconds();
        $loginServers = LoginServer::query()->get(['monitor_checked_at']);
        $gameServers = GameServer::query()->get(['monitor_checked_at']);

        if ($loginServers->isEmpty() && $gameServers->isEmpty()) {
            return false;
        }

        return $loginServers->contains(
            fn (LoginServer $server): bool => $this->checkedAtIsDue($server->monitor_checked_at, $cutoffTimestamp),
        ) || $gameServers->contains(
            fn (GameServer $server): bool => $this->checkedAtIsDue($server->monitor_checked_at, $cutoffTimestamp),
        );
    }

    /** @return array{refreshed:bool,refreshing:bool} */
    public function refreshIfDue(bool $force = false): array
    {
        if (! $force && ! $this->isDue()) {
            return ['refreshed' => false, 'refreshing' => false];
        }

        $lock = $this->lock();
        if ($lock === null || ! $lock->get()) {
            return ['refreshed' => false, 'refreshing' => true];
        }

        try {
            if (! $force && ! $this->isDue()) {
                return ['refreshed' => false, 'refreshing' => false];
            }

            $this->monitor->run();

            return ['refreshed' => true, 'refreshing' => false];
        } finally {
            try {
                $lock->release();
            } catch (Throwable $exception) {
                Log::warning('Server monitor lock release failed.', [
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    private function lock(): ?Lock
    {
        try {
            return Cache::lock('kaevcms:server-monitor:refresh', $this->lockSeconds());
        } catch (Throwable $exception) {
            Log::warning('Server monitor lock could not be created.', [
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    private function checkedAtIsDue(?CarbonInterface $checkedAt, int $cutoffTimestamp): bool
    {
        return ! $checkedAt instanceof CarbonInterface || $checkedAt->getTimestamp() < $cutoffTimestamp;
    }

    private function refreshIntervalSeconds(): int
    {
        return $this->settings->refreshIntervalSeconds();
    }

    private function lockSeconds(): int
    {
        return max(150, min(900, (int) config('cms.server_monitor.lock_seconds', 300)));
    }
}
