<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveGameAccountSettingsRequest;
use App\Services\AuditLogger;
use App\Services\GameAccountSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class GameAccountSettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(GameAccountSettings $settings): View
    {
        return view('admin.settings.game-accounts', ['settings' => $settings->values()]);
    }

    public function update(
        SaveGameAccountSettingsRequest $request,
        GameAccountSettings $settings,
    ): RedirectResponse {
        $before = $settings->values();
        $settings->update([
            'enabled' => $request->boolean('creation_enabled'),
            'max_accounts' => $request->integer('max_accounts'),
            'login_min' => $request->integer('login_min'),
            'login_max' => $request->integer('login_max'),
            'login_digit' => $request->boolean('login_digit'),
            'password_min' => $request->integer('password_min'),
            'password_max' => $request->integer('password_max'),
            'password_lower' => $request->boolean('password_lower'),
            'password_upper' => $request->boolean('password_upper'),
            'password_digit' => $request->boolean('password_digit'),
        ]);

        $this->auditLogger->success(
            category: 'admin',
            action: 'settings.game_accounts_updated',
            target: __('Game account settings'),
            details: ['before' => $before, 'after' => $settings->values()],
        );

        return redirect()
            ->route('admin.settings.game-accounts')
            ->with('status', __('Game account settings saved.'));
    }
}
