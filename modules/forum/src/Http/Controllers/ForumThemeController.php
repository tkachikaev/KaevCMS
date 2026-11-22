<?php

namespace KaevCMS\Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use KaevCMS\Modules\Forum\Services\ForumThemes;
use RuntimeException;

final class ForumThemeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(ForumThemes $themes): View
    {
        $installed = $themes->installed();

        return view('module-forum::admin.themes', [
            'themes' => $installed,
            'activeTheme' => $themes->active(),
            'readyCount' => count(array_filter($installed, static fn (array $theme): bool => $theme['valid'] && $theme['compatible'])),
        ]);
    }

    public function activate(Request $request, string $theme, ForumThemes $themes): RedirectResponse
    {
        $parameters = ['adminPath' => $request->route('adminPath')];
        $previous = $themes->activeSlug();

        try {
            $themes->activate($theme);
        } catch (RuntimeException $exception) {
            return redirect()->route('admin.module-pages.forum.themes', $parameters)
                ->withErrors(['theme' => $exception->getMessage()]);
        }

        $this->audit->success(
            category: 'forum',
            action: 'forum.theme_activated',
            actor: Auth::guard('admin')->user(),
            target: $theme,
            details: ['previous_theme' => $previous, 'active_theme' => $theme],
        );

        return redirect()->route('admin.module-pages.forum.themes', $parameters)
            ->with('status', __('module-forum::messages.theme_activated', ['theme' => $themes->active()['name']]));
    }
}
