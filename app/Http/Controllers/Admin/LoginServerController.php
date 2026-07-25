<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveLoginServerRequest;
use App\Models\LoginServer;
use App\Services\Servers\LoginServerAdministration;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class LoginServerController extends Controller
{
    public function index(): View
    {
        return view('admin.settings.login-server');
    }

    public function store(
        SaveLoginServerRequest $request,
        LoginServerAdministration $servers,
    ): RedirectResponse {
        $validated = $request->validated();

        if ($validated['connection_action'] === 'test') {
            $report = $servers->test($validated, (string) $validated['name']);

            return redirect()
                ->to(route('admin.settings.login-server').'#login-server-create')
                ->withInput($request->except('database_password'))
                ->with('database_connection_report', $report + ['context' => 'login-create']);
        }

        $servers->save(null, $validated);

        return redirect()
            ->route('admin.settings.login-server')
            ->with('status', __('LoginServer added.'));
    }

    public function update(
        SaveLoginServerRequest $request,
        LoginServer $loginServer,
        LoginServerAdministration $servers,
    ): RedirectResponse {
        $validated = $request->validated();

        if ($validated['connection_action'] === 'test') {
            $report = $servers->test($validated, $loginServer->name, $loginServer);

            return redirect()
                ->to(route('admin.settings.login-server').'#login-server-'.$loginServer->id)
                ->withInput($request->except('database_password'))
                ->with('database_connection_report', $report + ['context' => 'login-'.$loginServer->id]);
        }

        $servers->save($loginServer, $validated);

        return redirect()
            ->route('admin.settings.login-server')
            ->with('status', __('LoginServer settings saved.'));
    }

    public function destroy(
        LoginServer $loginServer,
        LoginServerAdministration $servers,
    ): RedirectResponse {
        $deleted = $servers->delete($loginServer);

        if ($deleted === null) {
            return back()->withErrors([
                'login_server' => __('The LoginServer is used by game servers or player accounts and cannot be deleted.'),
            ]);
        }

        return redirect()
            ->route('admin.settings.login-server')
            ->with('status', __('LoginServer :name deleted.', ['name' => $deleted['name']]));
    }
}
