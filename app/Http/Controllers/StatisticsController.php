<?php

namespace App\Http\Controllers;

use App\Models\GameServer;
use App\Services\GameWorld\GameStatistics;
use App\Services\SiteSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class StatisticsController
{
    public function index(Request $request, GameStatistics $statistics, SiteSettings $siteSettings): View
    {
        return $this->render($request, $statistics, $siteSettings);
    }

    public function indexLocalized(
        string $locale,
        Request $request,
        GameStatistics $statistics,
        SiteSettings $siteSettings,
    ): View {
        return $this->render($request, $statistics, $siteSettings);
    }

    public function show(
        Request $request,
        GameServer $gameServer,
        GameStatistics $statistics,
        SiteSettings $siteSettings,
    ): View {
        return $this->render($request, $statistics, $siteSettings, $gameServer);
    }

    public function showLocalized(
        string $locale,
        GameServer $gameServer,
        Request $request,
        GameStatistics $statistics,
        SiteSettings $siteSettings,
    ): View {
        return $this->render($request, $statistics, $siteSettings, $gameServer);
    }

    private function render(
        Request $request,
        GameStatistics $statistics,
        SiteSettings $siteSettings,
        ?GameServer $requestedServer = null,
    ): View {
        $servers = $this->publicServers();

        if ($requestedServer instanceof GameServer) {
            abort_unless($servers->contains(fn (GameServer $server): bool => $server->is($requestedServer)), 404);
            $selectedServer = $servers->first(fn (GameServer $server): bool => $server->is($requestedServer));
        } else {
            $selectedServer = $servers->first();
        }

        $sectionState = $selectedServer instanceof GameServer
            ? $statistics->sectionState($selectedServer)
            : ['available' => true, 'sections' => []];
        $sections = $sectionState['sections'];
        $requestedSection = trim((string) $request->query('section', ''));
        $activeSection = array_key_exists($requestedSection, $sections)
            ? $requestedSection
            : array_key_first($sections);
        $result = $sectionState['available'] && $selectedServer instanceof GameServer && is_string($activeSection)
            ? $statistics->load($selectedServer, $activeSection)
            : ['available' => false, 'rows' => []];

        return view('theme::statistics.index', [
            'servers' => $servers,
            'selectedServer' => $selectedServer,
            'sections' => $sections,
            'activeSection' => $activeSection,
            'statisticsSourceAvailable' => $sectionState['available'],
            'statisticsAvailable' => $result['available'],
            'rows' => $result['rows'],
            'showOnlineStatus' => $siteSettings->showPublicOnline(),
        ]);
    }

    /** @return Collection<int,GameServer> */
    private function publicServers(): Collection
    {
        return GameServer::query()
            ->with(['translations', 'loginServer'])
            ->where('statistics_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(static fn (GameServer $server): bool => $server->connectionConfigured())
            ->values();
    }
}
