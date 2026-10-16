<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Contracts\PaymentProvider;
use KaevCMS\Modules\Donations\Models\DonationCurrencyRate;
use KaevCMS\Modules\Donations\Models\DonationProviderSetting;
use KaevCMS\Modules\Donations\Models\DonationSetting;
use RuntimeException;
use Throwable;

/**
 * One independent configuration per installed provider. No checkout or remote API call in C1.
 * All credentials are stored as one encrypted payload, never returned to the view or audit.
 */
final class DonationProviderSettings
{
    public function __construct(
        private readonly DonationProviderRegistry $registry,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<array{code:string,name:string,enabled:bool,mode:string,currency_codes:list<string>,configured_fields:list<string>,supports_test:bool,supported_currencies:list<string>}> */
    public function overview(): array
    {
        $result = [];
        foreach ($this->registry->all() as $code => $driver) {
            $row = DonationProviderSetting::query()->find($code);
            $configured = [];
            if ($row !== null) {
                try {
                    $configured = array_keys($this->readCredentials($row));
                } catch (Throwable) {
                    // Never display a broken config as ready or leak the exception/secret.
                    $configured = [];
                }
            }
            $result[] = [
                'code' => $code,
                'name' => $driver->name(),
                'enabled' => $row !== null && $row->getRawOriginal('enabled') !== null
                    && in_array($row->getRawOriginal('enabled'), [true, 1, '1'], true),
                'mode' => $row?->mode === 'live' ? 'live' : 'test',
                'currency_codes' => is_array($row?->currency_codes) ? $row->currency_codes : [],
                'configured_fields' => $configured,
                'supports_test' => $driver->supportsTestMode(),
                'supported_currencies' => $driver->supportedCurrencies(),
            ];
        }

        return $result;
    }

    /** @return array<string,string> decrypted ONLY for internal trusted driver execution. */
    public function credentialsForDriver(string $code): array
    {
        $this->registry->get($code);
        $row = DonationProviderSetting::query()->find($code);
        if ($row === null) {
            throw new RuntimeException('Payment provider is not configured.');
        }

        return $this->readCredentials($row);
    }

    /**
     * @param  array<array-key, mixed>  $currencyCodes
     * @param  array<array-key, mixed>  $suppliedCredentials
     */
    public function save(
        Admin $admin,
        string $code,
        bool $enabled,
        string $mode,
        array $currencyCodes,
        array $suppliedCredentials = [],
    ): void {
        $this->authorizeManage($admin);
        $driver = $this->registry->get($code);
        if (! in_array($mode, ['test', 'live'], true) || ($mode === 'test' && ! $driver->supportsTestMode())) {
            throw new InvalidArgumentException('Unsupported provider mode.');
        }
        $allowed = array_unique($driver->supportedCurrencies());
        if (count($currencyCodes) > 200) {
            throw new InvalidArgumentException('Invalid provider currency selection.');
        }
        $validatedCurrencies = [];
        foreach ($currencyCodes as $currency) {
            if (! is_string($currency) || ! in_array($currency, $allowed, true)) {
                throw new InvalidArgumentException('Unsupported payment provider currency.');
            }
            $validatedCurrencies[] = $currency;
        }
        if (count($validatedCurrencies) !== count(array_unique($validatedCurrencies))) {
            throw new InvalidArgumentException('Invalid provider currency selection.');
        }
        $fields = $driver->credentialFields();
        $validatedCredentials = [];
        foreach ($suppliedCredentials as $key => $value) {
            if (! is_string($key) || ! array_key_exists($key, $fields) || ! is_string($value) || strlen($value) > 1024) {
                throw new InvalidArgumentException('Invalid payment provider configuration.');
            }
            $validatedCredentials[$key] = $value;
        }
        $currencyCodes = $validatedCurrencies;
        $suppliedCredentials = $validatedCredentials;
        DB::transaction(function () use ($admin, $code, $enabled, $mode, $currencyCodes, $suppliedCredentials, $fields, $driver): void {
            // Serialize the initial insert too, not only updates to an existing provider row.
            DonationSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $row = DonationProviderSetting::query()->whereKey($code)->lockForUpdate()->first();
            if ($row === null) {
                $row = new DonationProviderSetting;
                $row->forceFill(['provider_code' => $code, 'enabled' => false,
                    'mode' => 'test', 'currency_codes' => [], 'credentials_ciphertext' => null])->save();
                $row = DonationProviderSetting::query()->whereKey($code)->lockForUpdate()->firstOrFail();
            }
            $credentials = $this->readCredentials($row);
            foreach ($suppliedCredentials as $key => $value) {
                // Empty secret input means "keep", never "clear". Rotations overwrite atomically.
                if ($value !== '') {
                    $credentials[$key] = $value;
                }
            }
            // Driver declarations may evolve: strip retired keys before persisting.
            $credentials = array_intersect_key($credentials, $fields);
            if ($enabled) {
                if ($currencyCodes === []) {
                    throw new InvalidArgumentException('Select at least one provider currency.');
                }
                foreach ($fields as $key => $spec) {
                    if ($spec['required'] && (! isset($credentials[$key]) || $credentials[$key] === '')) {
                        throw new InvalidArgumentException('Required provider configuration is missing.');
                    }
                }
                $driver->assertConfiguration($credentials, $mode);
            }
            $row->forceFill([
                'enabled' => $enabled,
                'mode' => $mode,
                'currency_codes' => $currencyCodes,
                'credentials_ciphertext' => $credentials === [] ? null : Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR)),
                'updated_by_admin_id' => $admin->getKey(),
            ])->save();
            // Deliberately redact credential values and even secret fragments.
            if ($this->audit->success(category: 'module', action: 'donations.provider_updated', actor: $admin,
                target: 'donations-provider', details: [
                    'provider_code' => $code,
                    'enabled' => $enabled,
                    'mode' => $mode,
                    'currency_codes' => $currencyCodes,
                ]) === null) {
                throw new RuntimeException('Unable to audit Donations provider configuration.');
            }
        });
    }

    /**
     * Safe local checks only; a successful result does NOT prove the remote API or webhook works.
     *
     * @return array{state:string,matched_currencies:list<string>}
     */
    public function localDiagnostic(string $code): array
    {
        $driver = $this->registry->get($code);
        $row = DonationProviderSetting::query()->find($code);
        if ($row === null) {
            return ['state' => 'not_configured', 'matched_currencies' => []];
        }
        try {
            if (! in_array($row->mode, ['test', 'live'], true)
                || ($row->mode === 'test' && ! $driver->supportsTestMode())) {
                return ['state' => 'invalid', 'matched_currencies' => []];
            }
            $credentials = $this->readCredentials($row);
            foreach ($driver->credentialFields() as $field => $spec) {
                if ($spec['required'] && (! isset($credentials[$field]) || $credentials[$field] === '')) {
                    return ['state' => 'invalid', 'matched_currencies' => []];
                }
            }
            $driver->assertConfiguration($credentials, (string) $row->mode);
            $supported = $driver->supportedCurrencies();
            $currencies = $row->getAttribute('currency_codes') ?? [];
            if (! is_array($currencies) || $currencies === []
                || array_diff($currencies, $supported) !== []) {
                return ['state' => 'invalid', 'matched_currencies' => []];
            }
            $matched = [];
            foreach ($currencies as $currency) {
                if ($this->hasValidEnabledRate($currency)) {
                    $matched[] = $currency;
                }
            }
            if ($matched === []) {
                return ['state' => 'no_active_rate', 'matched_currencies' => []];
            }

            return ['state' => $row->enabled ? 'local_ok' : 'disabled', 'matched_currencies' => $matched];
        } catch (Throwable) {
            return ['state' => 'invalid', 'matched_currencies' => []];
        }
    }

    /** Live-ready provider is never inferred from a currency alone. */
    public function isAvailableForLivePayment(string $code, string $currencyCode): bool
    {
        try {
            $driver = $this->registry->get($code);
            $row = DonationProviderSetting::query()->find($code);
            if ($row === null || ! in_array($row->getRawOriginal('enabled'), [true, 1, '1'], true)
                || $row->mode !== 'live' || ! in_array($currencyCode, $driver->supportedCurrencies(), true)
                || ! in_array($currencyCode, $row->currency_codes ?? [], true)) {
                return false;
            }
            if (! $this->hasValidEnabledRate($currencyCode)) {
                return false;
            }
            $driver->assertConfiguration($this->readCredentials($row), 'live');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function hasValidEnabledRate(string $code): bool
    {
        $rate = DonationCurrencyRate::query()->where('currency_code', $code)->first();
        $currency = app(DonationCurrencyRegistry::class)->find($code, app()->getLocale());

        return $rate !== null && $currency !== null
            && in_array($rate->getRawOriginal('enabled'), [true, 1, '1'], true)
            && (int) $rate->exponent === $currency['exponent']
            && (int) $rate->unit_price_minor > 0
            && (int) $rate->unit_price_minor <= DonationMoney::MAX_UNIT_PRICE_MINOR;
    }

    /** @return list<string> */
    public function configuredCurrencies(PaymentProvider $driver): array
    {
        return array_values(array_intersect($driver->supportedCurrencies(),
            DonationCurrencyRate::query()->pluck('currency_code')->all()));
    }

    private function authorizeManage(Admin $admin): void
    {
        if (! in_array($admin->role, [AdminRole::Owner, AdminRole::Administrator], true)) {
            throw new AuthorizationException;
        }
    }

    /** @return array<string,string> */
    private function readCredentials(DonationProviderSetting $row): array
    {
        if ($row->credentials_ciphertext === null) {
            return [];
        }
        try {
            $credentials = json_decode(Crypt::decryptString($row->credentials_ciphertext), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($credentials)) {
                throw new RuntimeException('Invalid provider configuration.');
            }
            foreach ($credentials as $key => $value) {
                if (! is_string($key) || ! is_string($value)) {
                    throw new RuntimeException('Invalid provider configuration.');
                }
            }

            return $credentials;
        } catch (Throwable) {
            throw new RuntimeException('Unable to read protected provider configuration.');
        }
    }
}
