<?php

namespace KaevCMS\Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Admin\AdminPathSettings;
use App\Support\Modules\ModuleAdminAuthorizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Services\DonationProviderRegistry;
use KaevCMS\Modules\Donations\Services\DonationProviderSettings;

final class AdminDonationProviderController extends Controller
{
    public function index(AdminPathSettings $paths, DonationProviderSettings $settings): View
    {
        /** @var view-string $view */
        $view = 'module-donations::admin.providers';

        return view($view, [
            'adminPath' => $paths->path(),
            'providers' => $settings->overview(),
        ]);
    }

    public function show(string $providerCode, AdminPathSettings $paths, DonationProviderSettings $settings,
        DonationProviderRegistry $registry): View
    {
        try {
            $driver = $registry->get($providerCode);
        } catch (InvalidArgumentException) {
            abort(404);
        }
        $state = collect($settings->overview())->firstWhere('code', $providerCode);
        abort_unless($state !== null, 404);

        /** @var view-string $view */
        $view = 'module-donations::admin.provider';

        return view($view, [
            'adminPath' => $paths->path(), 'driver' => $driver, 'state' => $state,
            'fields' => $driver->credentialFields(),
            'availableCurrencies' => $settings->configuredCurrencies($driver),
            'diagnostic' => $settings->localDiagnostic($providerCode),
        ]);
    }

    public function update(string $providerCode, Request $request, AdminPathSettings $paths,
        ModuleAdminAuthorizer $access, DonationProviderSettings $settings): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);
        $access->authorize('admin.module-pages.donations.providers.update', 'PUT', $admin);

        // Explicit failure handling avoids flashing API keys/passwords into session old input.
        $enabled = $request->input('enabled');
        $mode = $request->input('mode');
        $codes = $request->input('currency_codes', []);
        $credentials = $request->input('credentials', []);
        if (! in_array($enabled, ['0', '1', 0, 1], true) || ! is_string($mode)
            || ! is_array($codes) || ! is_array($credentials)) {
            return back()->withErrors(['provider' => __('module-donations::messages.provider_invalid_config')]);
        }
        // Laravel ConvertEmptyStringsToNull turns blank password controls into null.
        // Preserve an existing credential when the owner leaves its field blank.
        foreach ($credentials as $field => $value) {
            if ($value === null) {
                $credentials[$field] = '';
            }
        }
        try {
            $settings->save($admin, $providerCode, (string) $enabled === '1', $mode, $codes, $credentials);
        } catch (InvalidArgumentException) {
            return back()->withErrors(['provider' => __('module-donations::messages.provider_invalid_config')]);
        }

        return redirect()->route('admin.module-pages.donations.providers.show', [
            'adminPath' => $paths->path(), 'providerCode' => $providerCode,
        ])->with('status', __('module-donations::messages.provider_saved'));
    }
}
