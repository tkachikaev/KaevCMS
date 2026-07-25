<?php

namespace App\Http\Controllers;

use App\Models\GameServer;
use App\Models\News;
use App\Services\GameServerSettings;
use App\Services\GameWorld\GameStatistics;
use App\Services\Servers\ServerMonitorCoordinator;
use App\Services\Servers\ServerStatusOverview;
use Illuminate\View\View;

final class HomeController
{
    public function __invoke(
        GameServerSettings $gameServerSettings,
        GameStatistics $statistics,
        ServerStatusOverview $statuses,
        ServerMonitorCoordinator $monitorCoordinator,
    ): View {
        $news = News::query()->with('translations')->published()->latest('published_at')->limit(3)->get();
        $monitor = $statuses->get();
        $statusById = collect($monitor['game_servers'])->keyBy('id');
        $servers = array_map(
            static function (array $server) use ($statusById): array {
                $status = $statusById->get($server['id']);
                if (! is_array($status)) {
                    $status = [
                        'state' => 'unknown',
                        'availability_state' => 'unknown',
                        'players' => null,
                        'public_players' => null,
                        'maintenance_message' => $server['maintenance_message'],
                        'checked_at' => null,
                    ];
                }

                return array_merge($server, $status, [
                    'state' => $status['availability_state'] ?? 'unknown',
                ]);
            },
            $gameServerSettings->all(),
        );

        return view('theme::home', [
            'news' => $news,
            'server' => $servers[0] ?? null,
            'servers' => $servers,
            'topCharacters' => $this->topCharacters($statistics),
            'monitorRefreshDue' => $monitorCoordinator->isDue(),
            'publicOnlineVisible' => (bool) $monitor['public_online_visible'],
        ]);
    }

    /** @return list<array{name:string,class:string,level:int,race_key:string,gender_key:string,archetype:string,avatar_url:?string}> */
    private function topCharacters(GameStatistics $statistics): array
    {
        $servers = GameServer::query()
            ->where('statistics_enabled', true)
            ->where('statistics_level_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($servers as $server) {
            if (! $server->connectionConfigured()) {
                continue;
            }

            $state = $statistics->sectionState($server);
            if (! $state['available'] || ! array_key_exists('level', $state['sections'])) {
                continue;
            }

            $ranking = $statistics->load($server, 'level');
            if (! $ranking['available']) {
                continue;
            }

            return array_map(
                static fn (array $row): array => [
                    'name' => trim((string) ($row['name'] ?? '')),
                    'class' => trim((string) ($row['class_name'] ?? '')),
                    'level' => max(0, (int) ($row['level'] ?? 0)),
                    'race_key' => trim((string) ($row['race_key'] ?? 'unknown')),
                    'gender_key' => trim((string) ($row['gender_key'] ?? 'neutral')),
                    'archetype' => trim((string) ($row['archetype'] ?? 'default')),
                    'avatar_url' => is_string($row['avatar_url'] ?? null) ? $row['avatar_url'] : null,
                ],
                array_slice($ranking['rows'], 0, 5),
            );
        }

        return [];
    }
}
