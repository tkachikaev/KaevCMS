<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveWalletAppearanceRequest;
use App\Services\AuditLogger;
use App\Services\Settings\SettingsImageStorage;
use App\Services\Wallet\WalletAppearanceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

final class WalletAppearanceController extends Controller
{
    public function index(WalletAppearanceSettings $settings): View
    {
        return view('admin.settings.wallet-appearance', [
            'settings' => $settings->values(),
            'translations' => $settings->translations(),
        ]);
    }

    public function update(
        SaveWalletAppearanceRequest $request,
        WalletAppearanceSettings $settings,
        SettingsImageStorage $images,
        AuditLogger $audit,
    ): RedirectResponse {
        $validated = $request->validated();
        $before = $settings->values();
        $savedIcon = null;

        try {
            if ($request->hasFile('icon')) {
                $savedIcon = $images->store($request->file('icon'), 'wallet-coin');
            }
            $icon = $savedIcon ?? ($request->boolean('remove_icon') ? null : $before['icon']);
            $settings->update(
                (string) ($validated['symbol'] ?? ''),
                $icon,
                $validated['translations'],
            );
        } catch (Throwable $exception) {
            if ($savedIcon !== null) {
                $images->delete($savedIcon, 'wallet-coin');
            }
            throw $exception;
        }

        // The old image is removed only after the new settings were persisted.
        if ($before['icon'] !== null && $before['icon'] !== $icon) {
            $images->delete($before['icon'], 'wallet-coin');
        }

        $audit->success(
            category: 'admin',
            action: 'settings.wallet_appearance_updated',
            target: __('Wallet appearance'),
            details: [
                'icon_changed' => $before['icon'] !== $icon,
                'symbol_changed' => $before['symbol'] !== ($validated['symbol'] ?? ''),
                'languages' => array_keys($validated['translations']),
            ],
        );

        return redirect()->route('admin.settings.wallet-appearance')
            ->with('status', __('Wallet appearance saved.'));
    }
}
