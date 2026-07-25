<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\Themes\AccountThemeManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;

class AccountThemeController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(AccountThemeManager $themes): View
    {
        $installedThemes = $themes->installed();

        return view('admin.account-themes.index', [
            'themes' => $installedThemes,
            'activeThemeSlug' => $themes->name(),
            'validThemeCount' => collect($installedThemes)
                ->filter(fn (array $theme): bool => $theme['valid'] && $theme['compatible'])
                ->count(),
        ]);
    }

    public function activate(Request $request, string $theme, AccountThemeManager $themes): RedirectResponse
    {
        $previousTheme = $themes->name();

        try {
            $themes->activate($theme);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.account-themes.index')
                ->withErrors(['theme' => $exception->getMessage()]);
        }

        Log::notice('Player account theme activated.', [
            'admin_id' => Auth::guard('admin')->id(),
            'previous_theme' => $previousTheme,
            'active_theme' => $theme,
            'ip_address' => $request->ip(),
        ]);

        $this->auditLogger->success(
            category: 'admin',
            action: 'account_theme.activated',
            target: $theme,
            details: [
                'previous_theme' => $previousTheme,
                'active_theme' => $theme,
            ],
        );

        return redirect()
            ->route('admin.account-themes.index')
            ->with('status', __('Account theme :theme activated.', ['theme' => $theme]));
    }
}
