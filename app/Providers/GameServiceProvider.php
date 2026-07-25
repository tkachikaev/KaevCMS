<?php

namespace App\Providers;

use App\Contracts\ExternalDatabaseConnectionTester;
use App\Contracts\GameAccountGateway;
use App\Contracts\GameRewardQueueGateway;
use App\Contracts\GameServerDatabaseGateway;
use App\Contracts\GameServerOnlineCounter;
use App\Contracts\ServicePortProbe;
use App\Services\GameAccounts\ExternalGameAccountGateway;
use App\Services\GameWorld\MySqlGameServerDatabase;
use App\Services\Rewards\DatabaseGameRewardQueueGateway;
use App\Services\Servers\MySqlExternalDatabaseConnectionTester;
use App\Services\Servers\MySqlGameServerOnlineCounter;
use App\Services\Servers\TcpServicePortProbe;
use Illuminate\Support\ServiceProvider;

class GameServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExternalDatabaseConnectionTester::class, MySqlExternalDatabaseConnectionTester::class);
        $this->app->singleton(GameAccountGateway::class, ExternalGameAccountGateway::class);
        $this->app->singleton(ServicePortProbe::class, TcpServicePortProbe::class);
        $this->app->singleton(GameServerOnlineCounter::class, MySqlGameServerOnlineCounter::class);
        $this->app->singleton(GameServerDatabaseGateway::class, MySqlGameServerDatabase::class);
        $this->app->singleton(GameRewardQueueGateway::class, DatabaseGameRewardQueueGateway::class);
    }
}
