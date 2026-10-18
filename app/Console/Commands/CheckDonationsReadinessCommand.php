<?php

namespace App\Console\Commands;

use App\Support\Modules\ModuleManager;
use App\Support\Payments\DonationsTrustedAutoload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use KaevCMS\Modules\Donations\Services\DonationLiveActivation;
use KaevCMS\Modules\Donations\Services\DonationProviderSettings;
use KaevCMS\Modules\Donations\Services\DonationSettings;
use Throwable;

/** Local operational preflight. Never makes an HTTP call or changes payment settings. */
final class CheckDonationsReadinessCommand extends Command
{
    protected $signature = 'kaevcms:donations-readiness';

    protected $description = 'Check deployment gates and local YooMoney setup without revealing secrets or making a transfer';

    public function handle(): int
    {
        $failures = 0;
        $check = function (string $name, bool $ok) use (&$failures): void {
            $this->line(($ok ? '[OK] ' : '[BLOCKED] ').$name);
            if (! $ok) {
                $failures++;
            }
        };

        $check('Production environment', app()->environment('production'));
        $check('Application debug disabled', config('app.debug') === false);
        $check('Public APP_URL uses HTTPS', is_string(config('app.url'))
            && str_starts_with(config('app.url'), 'https://'));
        $check('APP_KEY is configured', is_string(config('app.key'))
            && strlen(config('app.key')) >= 32);
        $check('MySQL/MariaDB is selected', config('database.default') === 'mysql');
        $check('Signed callbacks remain reachable', config('donation-payments.callbacks_enabled') === true);
        $check('Verified Wallet settlement remains available', config('donation-payments.credits_enabled') === true
            && config('wallet.enabled_sources.donations') === true);
        try {
            // The command must also diagnose a fresh or disabled module, whose
            // namespace has not been registered by the normal UI runtime.
            DonationsTrustedAutoload::register();
            $activation = app(DonationLiveActivation::class);
            $state = $activation->state();
            $check('Payment mode selected in Donations admin', $state['mode'] !== 'off');
            if ($state['mode'] !== 'off') {
                $this->line('[INFO] Checkout audience: '.($state['mode'] === 'pilot' ? 'selected player only.' : 'all eligible site users.'));
            }
            $check('Payment setup readiness', $activation->blockers() === []);

            $tables = ['module_donation_settings', 'module_donation_currency_rates',
                'module_donation_orders', 'module_donation_payment_preparations',
                'module_donation_provider_settings', 'module_donation_payment_receipts',
                'module_donation_payment_settlements', 'wallets', 'wallet_entries'];
            $available = true;
            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) {
                    $available = false;
                    break;
                }
            }
            $check('Donation and Wallet database tables installed', $available);
            if ($available) {
                $module = app(ModuleManager::class)->inspect('donations');
                $check('Donations module enabled', ($module['status'] ?? null) === 'enabled');
                $check('Donations visible to players', app(DonationSettings::class)->isVisibleToPlayers());
                $provider = app(DonationProviderSettings::class);
                $state = collect($provider->overview())->firstWhere('code', 'yoomoney');
                $check('YooMoney provider enabled in live mode', is_array($state)
                    && $state['enabled'] === true && $state['mode'] === 'live');
                $diagnostic = $provider->localDiagnostic('yoomoney');
                $check('YooMoney local credentials and active RUB tariff',
                    $diagnostic['state'] === 'local_ok'
                    && in_array('RUB', $diagnostic['matched_currencies'], true));
            }
        } catch (Throwable) {
            // No exception strings here: they could contain credentials or DSNs.
            $check('Local database and provider diagnostics', false);
        }

        $this->newLine();
        if ($failures > 0) {
            $this->error($failures.' local readiness checks are blocked. No settings were changed.');

            return self::FAILURE;
        }

        $this->info('Local configuration looks ready. This is NOT proof of callback reachability, merchant eligibility, or a successful payment.');
        $this->line('Verify HTTPS/proxy handling and conduct ONE approved, monitored live transfer before public launch.');

        return self::SUCCESS;
    }
}
