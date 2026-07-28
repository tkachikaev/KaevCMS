<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\CharacterRescueGateway;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveGameServerFeatureRequest;
use App\Models\GameServer;
use App\Models\GameServerFeature;
use App\Services\AuditLogger;
use App\Services\GameServerFeatures\GameServerFeatureSettings;
use App\Support\GameServerFeatures\CharacterRescueCapabilities;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class GameServerFeatureController extends Controller
{
    public function __construct(
        private readonly GameServerFeatureSettings $features,
        private readonly CharacterRescueGateway $rescueGateway,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        $servers = GameServer::query()
            ->with('translations')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $enabled = GameServerFeature::query()
            ->where('feature_key', GameServerFeatureSettings::CHARACTER_RESCUE)
            ->where('enabled', true)
            ->pluck('game_server_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return view('admin.settings.game-server-features.index', [
            'servers' => $servers,
            'enabledServerIds' => $enabled,
        ]);
    }

    public function edit(GameServer $gameServer): View
    {
        $gameServer->loadMissing('translations');
        $capabilities = new CharacterRescueCapabilities(false);
        $capabilityError = false;

        if ($this->rescueGateway->supports($gameServer)) {
            try {
                $capabilities = $this->rescueGateway->capabilities($gameServer);
            } catch (Throwable) {
                $capabilityError = true;
            }
        }

        return view('admin.settings.game-server-features.edit', [
            'gameServer' => $gameServer,
            'rescue' => $this->features->characterRescue($gameServer),
            'capabilities' => $capabilities,
            'capabilityError' => $capabilityError,
        ]);
    }

    public function update(
        SaveGameServerFeatureRequest $request,
        GameServer $gameServer,
    ): RedirectResponse {
        $before = $this->features->characterRescue($gameServer);
        $after = [
            'enabled' => $request->boolean('enabled'),
            'location_name' => trim((string) $request->input('location_name')),
            'x' => $request->integer('x'),
            'y' => $request->integer('y'),
            'z' => $request->integer('z'),
            'offline_delay_minutes' => $request->integer('offline_delay_minutes'),
            'cooldown_hours' => $request->integer('cooldown_hours'),
        ];

        $this->features->updateCharacterRescue($gameServer, $after);
        $this->audit->success(
            category: 'server',
            action: 'game_server.character_rescue_settings_updated',
            target: $gameServer,
            details: ['before' => $before, 'after' => $after],
        );

        return redirect()
            ->route('admin.settings.game-server-features.edit', $gameServer)
            ->with('status', __('Game server features saved.'));
    }
}
