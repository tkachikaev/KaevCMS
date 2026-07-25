<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\InteractsWithSettingsAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveGeneralSettingsRequest;
use App\Services\AuditLogger;
use App\Services\Localization\LanguageManager;
use App\Services\Settings\SettingsImageStorage;
use App\Services\SiteSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class GeneralSettingsController extends Controller
{
    use InteractsWithSettingsAudit;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function general(SiteSettings $siteSettings): View
    {
        return view('admin.settings.general', [
            'settings' => $siteSettings->values(),
            'translations' => $siteSettings->translations(),
            'languages' => app(LanguageManager::class)->enabled(),
            'defaultLocale' => app(LanguageManager::class)->default(),
            'timezones' => timezone_identifiers_list(),
        ]);
    }

    public function updateGeneral(
        SaveGeneralSettingsRequest $request,
        SiteSettings $siteSettings,
        SettingsImageStorage $images,
    ): RedirectResponse {
        $validated = $request->validated();
        $current = $siteSettings->values();
        $storedLogo = null;
        $storedFavicon = null;

        try {
            if ($request->hasFile('logo')) {
                $storedLogo = $images->store($request->file('logo'), 'logo');
            }

            if ($request->hasFile('favicon')) {
                $storedFavicon = $images->store($request->file('favicon'), 'favicon');
            }

            $logo = $storedLogo
                ?? ($request->boolean('remove_logo') ? null : $current['logo']);
            $favicon = $storedFavicon
                ?? ($request->boolean('remove_favicon') ? null : $current['favicon']);

            $translations = is_array($validated['translations'] ?? null)
                ? $validated['translations']
                : [];

            $siteSettings->update([
                'name' => (string) ($validated['site_name'] ?? $current['name']),
                'description' => (string) ($validated['site_description'] ?? $current['description']),
                'logo' => $logo,
                'favicon' => $favicon,
                'timezone' => (string) $validated['timezone'],
                'admin_email' => (string) ($validated['admin_email'] ?? ''),
                'footer_text' => (string) ($validated['footer_text'] ?? $current['footer_text']),
                'show_public_online' => (bool) ($validated['show_public_online'] ?? $current['show_public_online']),
            ], $translations);
        } catch (Throwable $exception) {
            if ($storedLogo !== null) {
                $images->delete($storedLogo, 'logo');
            }

            if ($storedFavicon !== null) {
                $images->delete($storedFavicon, 'favicon');
            }

            throw $exception;
        }

        if ($current['logo'] !== null && $current['logo'] !== $logo) {
            $images->delete($current['logo'], 'logo');
        }

        if ($current['favicon'] !== null && $current['favicon'] !== $favicon) {
            $images->delete($current['favicon'], 'favicon');
        }

        $after = $siteSettings->values();
        $this->auditLogger->success(
            category: 'admin',
            action: 'settings.general_updated',
            target: __('General settings'),
            details: [
                'changes' => $this->auditChanges(
                    $this->generalAuditValues($current),
                    $this->generalAuditValues($after),
                ),
                'logo_changed' => $current['logo'] !== $after['logo'],
                'favicon_changed' => $current['favicon'] !== $after['favicon'],
            ],
        );

        return redirect()
            ->route('admin.settings.general')
            ->with('status', __('General settings saved.'));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string|bool>
     */
    private function generalAuditValues(array $values): array
    {
        return [
            'name' => $values['name'] ?? '',
            'description' => $values['description'] ?? '',
            'timezone' => $values['timezone'] ?? '',
            'admin_email' => $values['admin_email'] ?? '',
            'footer_text' => $values['footer_text'] ?? '',
            'logo_configured' => ! empty($values['logo']),
            'favicon_configured' => ! empty($values['favicon']),
            'show_public_online' => (bool) ($values['show_public_online'] ?? true),
        ];
    }
}
