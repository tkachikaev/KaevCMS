<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveGameServerConnectionRequest;
use App\Models\GameServer;
use App\Models\LoginServer;
use App\Services\Servers\GameServerAdministration;
use Illuminate\Http\RedirectResponse;

class GameServerConnectionController extends Controller
{
    public function update(
        SaveGameServerConnectionRequest $request,
        GameServer $gameServer,
        GameServerAdministration $servers,
    ): RedirectResponse {
        $validated = $request->validated();
        $loginServer = LoginServer::query()->findOrFail((int) $validated['login_server_id']);

        if ($validated['connection_action'] === 'test') {
            $report = $servers->test(
                $validated,
                $loginServer,
                $gameServer,
                $gameServer->name,
            );

            return redirect()
                ->to(route('admin.settings.game-server').'#game-server-'.$gameServer->id.'-connection')
                ->withInput($request->except('database_password'))
                ->with('database_connection_report', $report + ['context' => 'game-'.$gameServer->id]);
        }

        $servers->updateConnection($gameServer, $validated);

        return redirect()
            ->route('admin.settings.game-server')
            ->with('status', __('GameServer database connection saved.'));
    }
}
