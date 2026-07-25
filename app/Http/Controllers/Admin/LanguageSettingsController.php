<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\InteractsWithSettingsAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveLanguageSettingsRequest;
use App\Services\AuditLogger;
use App\Services\Localization\LanguageManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class LanguageSettingsController extends Controller
{
    use InteractsWithSettingsAudit;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function languages(LanguageManager $languages): View
    {
        return view('admin.settings.languages', [
            'installedLanguages' => $languages->installed(),
            'enabledLocales' => $languages->enabledCodes(),
            'defaultLocale' => $languages->default(),
            'fallbackLocale' => $languages->fallback(),
        ]);
    }

    public function updateLanguages(
        SaveLanguageSettingsRequest $request,
        LanguageManager $languages,
    ): RedirectResponse {
        $validated = $request->validated();
        $before = [
            'enabled' => $languages->enabledCodes(),
            'default' => $languages->default(),
            'fallback' => $languages->fallback(),
        ];

        $languages->update(
            enabled: array_values(array_map('strval', (array) $validated['enabled_locales'])),
            default: (string) $validated['default_locale'],
            fallback: (string) $validated['fallback_locale'],
        );

        $after = [
            'enabled' => $languages->enabledCodes(),
            'default' => $languages->default(),
            'fallback' => $languages->fallback(),
        ];

        $this->auditLogger->success(
            category: 'admin',
            action: 'settings.languages_updated',
            target: __('Language settings'),
            details: ['changes' => $this->auditChanges($before, $after)],
        );

        return redirect()
            ->route('admin.settings.languages')
            ->with('status', __('Language settings saved.'));
    }
}
