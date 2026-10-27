<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Contracts\PaymentProvider;
use KaevCMS\Modules\Donations\Models\DonationCurrencyRate;
use KaevCMS\Modules\Donations\Models\DonationPaymentPreparation;
use KaevCMS\Modules\Donations\Models\DonationProviderSetting;
use KaevCMS\Modules\Donations\Models\DonationSetting;
use RuntimeException;
use Throwable;

/**
 * One independent configuration per installed provider. C2 checkout is inert.
 * All credentials are stored as one encrypted payload, never returned to the view or audit.
 */
final class DonationProviderSettings
{
    // Encrypted inside the existing credential payload, never a field in the UI.
    // At most four retired YooMoney secrets are retained for 24 hours after rotation.
    private const YOOMONEY_RETIRED_KEYS = '_yoomoney_retired_hmac_keys';

    private const YOOMONEY_GRACE_HOURS = 24;

    private const FREEKASSA_RETIRED_KEYS = '_freekassa_retired_secret2_keys';

    private const FREEKASSA_GRACE_HOURS = 24;

    private const FREEKASSA_API_NONCE = '_freekassa_api_last_nonce';

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
                    $configured = array_keys(array_intersect_key(
                        $this->readCredentials($row), $driver->credentialFields(),
                    ));
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

        return array_intersect_key($this->readCredentials($row), $this->registry->get($code)->credentialFields());
    }

    /**
     * Trusted signing keys for ONE immutable payment preparation. Rotated keys are
     * accepted only for orders prepared before rotation, and for a short grace
     * period. The read-only provider UI and API never expose prior secrets.
     *
     * @return list<array<string,string>>
     */
    public function notificationVerificationCredentials(DonationPaymentPreparation $preparation): array
    {
        $code = (string) $preparation->provider_code;
        $driver = $this->registry->get($code);
        $row = DonationProviderSetting::query()->find($code);
        if ($row === null) {
            throw new RuntimeException('Payment provider is not configured.');
        }
        $stored = $this->readCredentials($row);
        $current = array_intersect_key($stored, $driver->credentialFields());
        $result = [$current];
        if ($preparation->mode !== 'live' || ! in_array($code, ['yoomoney', 'freekassa'], true)) {
            return $result;
        }
        $now = CarbonImmutable::now();
        $retiredKeys = $code === 'yoomoney' ? $this->readRetiredYooMoneyKeys($stored)
            : $this->readRetiredFreeKassaKeys($stored);
        foreach ($retiredKeys as $retired) {
            $expiresAt = CarbonImmutable::parse($retired['expires_at']);
            // The serialized provider-settings lock also guards preparation.
            // Compare the immutable ID boundary, not seconds-precision timestamps.
            if ((int) $preparation->getKey() <= $retired['max_preparation_id']
                && $now->lessThan($expiresAt)) {
                $result[] = [...$current, $code === 'yoomoney' ? 'notification_secret' : 'secret_2' => $retired['secret']];
            }
        }

        return $result;
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
        ?array $callbackSenderIps = null,
        ?array $callbackProxyIps = null,
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
        if ($code !== 'freekassa' && ($callbackSenderIps !== null || $callbackProxyIps !== null)) {
            throw new InvalidArgumentException('Unexpected provider callback IP policy.');
        }
        if (($callbackSenderIps !== null && ! FreeKassaCallbackSecurity::validateStored($callbackSenderIps))
            || ($callbackProxyIps !== null && ! FreeKassaCallbackSecurity::validateStored($callbackProxyIps, true))) {
            throw new InvalidArgumentException('Invalid FreeKassa callback IP policy.');
        }
        $currencyCodes = $validatedCurrencies;
        $suppliedCredentials = $validatedCredentials;
        DB::transaction(function () use ($admin, $code, $enabled, $mode, $currencyCodes, $suppliedCredentials, $callbackSenderIps, $callbackProxyIps, $fields, $driver): void {
            // Serialize the initial insert too, not only updates to an existing provider row.
            DonationSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $row = DonationProviderSetting::query()->whereKey($code)->lockForUpdate()->first();
            if ($row === null) {
                $row = new DonationProviderSetting;
                $row->forceFill(['provider_code' => $code, 'enabled' => false,
                    'mode' => 'test', 'currency_codes' => [], 'credentials_ciphertext' => null])->save();
                $row = DonationProviderSetting::query()->whereKey($code)->lockForUpdate()->firstOrFail();
            }
            $stored = $this->readCredentials($row);
            $credentials = array_intersect_key($stored, $fields);
            $oldSecret = $credentials['notification_secret'] ?? null;
            $oldFreeKassaSecret = $credentials['secret_2'] ?? null;
            foreach ($suppliedCredentials as $key => $value) {
                // Empty secret input means "keep", never "clear". Rotations overwrite atomically.
                if ($value !== '') {
                    $credentials[$key] = $value;
                }
            }
            // Preserve a bounded, encrypted verification grace for existing live
            // preparations when YooMoney or FreeKassa rotate callback secrets.
            $retired = [];
            if ($code === 'yoomoney') {
                $now = CarbonImmutable::now();
                foreach ($this->readRetiredYooMoneyKeys($stored) as $entry) {
                    if ($now->lessThan(CarbonImmutable::parse($entry['expires_at']))) {
                        $retired[] = $entry;
                    }
                }
                if (is_string($oldSecret) && $oldSecret !== ''
                    && isset($credentials['notification_secret'])
                    && $oldSecret !== $credentials['notification_secret']) {
                    $retired[] = [
                        'secret' => $oldSecret,
                        'rotated_at' => $now->toIso8601String(),
                        'max_preparation_id' => (int) (DonationPaymentPreparation::query()
                            ->where('provider_code', 'yoomoney')->max('id') ?? 0),
                        'expires_at' => $now->addHours(self::YOOMONEY_GRACE_HOURS)->toIso8601String(),
                    ];
                }
                if ($retired !== []) {
                    // Bound encrypted data growth; extraordinary repeated rotations
                    // require manual verification of oldest delayed transfers.
                    $credentials[self::YOOMONEY_RETIRED_KEYS] = json_encode(
                        array_slice($retired, -4), JSON_THROW_ON_ERROR,
                    );
                }
            }
            if ($code === 'freekassa') {
                $now = CarbonImmutable::now();
                $retiredFreeKassa = [];
                foreach ($this->readRetiredFreeKassaKeys($stored) as $entry) {
                    if ($now->lessThan(CarbonImmutable::parse($entry['expires_at']))) {
                        $retiredFreeKassa[] = $entry;
                    }
                }
                if (is_string($oldFreeKassaSecret) && $oldFreeKassaSecret !== ''
                    && isset($credentials['secret_2']) && $oldFreeKassaSecret !== $credentials['secret_2']) {
                    $retiredFreeKassa[] = [
                        'secret' => $oldFreeKassaSecret,
                        'rotated_at' => $now->toIso8601String(),
                        'max_preparation_id' => (int) (DonationPaymentPreparation::query()
                            ->where('provider_code', 'freekassa')->max('id') ?? 0),
                        'expires_at' => $now->addHours(self::FREEKASSA_GRACE_HOURS)->toIso8601String(),
                    ];
                }
                if ($retiredFreeKassa !== []) {
                    $credentials[self::FREEKASSA_RETIRED_KEYS] = json_encode(
                        array_slice($retiredFreeKassa, -4), JSON_THROW_ON_ERROR,
                    );
                }
                if (isset($stored[self::FREEKASSA_API_NONCE])) {
                    $credentials[self::FREEKASSA_API_NONCE] = $stored[self::FREEKASSA_API_NONCE];
                }
                // Dedicated metadata is inside the existing encrypted payload;
                // the public driver never receives the callback security settings.
                foreach ([
                    FreeKassaCallbackSecurity::SENDERS_KEY => $callbackSenderIps,
                    FreeKassaCallbackSecurity::PROXIES_KEY => $callbackProxyIps,
                ] as $key => $values) {
                    if ($values !== null) {
                        $credentials[$key] = json_encode($values, JSON_THROW_ON_ERROR);
                    } elseif (isset($stored[$key])) {
                        $credentials[$key] = $stored[$key];
                    }
                }
                if ($enabled && $callbackSenderIps === []) {
                    throw new InvalidArgumentException('Cannot enable FreeKassa with no trusted callback addresses.');
                }
                if ($enabled && $callbackSenderIps === null && isset($stored[FreeKassaCallbackSecurity::SENDERS_KEY])
                    && $this->freeKassaPolicyList($stored, FreeKassaCallbackSecurity::SENDERS_KEY, false) === []) {
                    throw new InvalidArgumentException('Cannot enable FreeKassa with no trusted callback addresses.');
                }
            }
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
     * FreeKassa requires strictly increasing API nonces. Serialize the counter
     * through the existing provider settings row, including across PHP workers.
     * Internal metadata is encrypted and never passed to the provider driver.
     */
    public function nextFreeKassaApiNonce(string $merchantId): int
    {
        return DB::transaction(function () use ($merchantId): int {
            $row = DonationProviderSetting::query()->whereKey('freekassa')->lockForUpdate()->firstOrFail();
            $stored = $this->readCredentials($row);
            if (($stored['merchant_id'] ?? '') !== $merchantId) {
                throw new RuntimeException('FreeKassa merchant configuration changed.');
            }
            $old = $stored[self::FREEKASSA_API_NONCE] ?? '0';
            if (! preg_match('/\A(?:0|[1-9][0-9]{0,17})\z/D', $old)) {
                throw new RuntimeException('FreeKassa API nonce storage is invalid.');
            }
            $nonce = max(time(), (int) $old + 1);
            $stored[self::FREEKASSA_API_NONCE] = (string) $nonce;
            $row->forceFill(['credentials_ciphertext' => Crypt::encryptString(
                json_encode($stored, JSON_THROW_ON_ERROR),
            )])->save();

            return $nonce;
        }, 3);
    }

    /** @return array{senders:list<string>,proxies:list<string>,customized:bool} */
    public function freeKassaCallbackPolicy(): array
    {
        $row = DonationProviderSetting::query()->find('freekassa');
        $stored = $row === null ? [] : $this->readCredentials($row);

        return [
            'senders' => $this->freeKassaPolicyList($stored, FreeKassaCallbackSecurity::SENDERS_KEY, false),
            'proxies' => $this->freeKassaPolicyList($stored, FreeKassaCallbackSecurity::PROXIES_KEY, true),
            'customized' => array_key_exists(FreeKassaCallbackSecurity::SENDERS_KEY, $stored),
        ];
    }

    /** @param array<string,string> $stored
     *  @return list<string>
     */
    private function freeKassaPolicyList(array $stored, string $key, bool $proxies): array
    {
        if (! array_key_exists($key, $stored)) {
            return $proxies ? [] : FreeKassaCallbackSecurity::INITIAL_SENDERS;
        }
        try {
            $items = json_decode($stored[$key], true, 512, JSON_THROW_ON_ERROR);
            if (! FreeKassaCallbackSecurity::validateStored($items, $proxies)) {
                throw new RuntimeException('Invalid stored FreeKassa IP policy.');
            }

            return $items;
        } catch (Throwable) {
            throw new RuntimeException('Unable to read FreeKassa callback security policy.');
        }
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
            if ($code === 'freekassa' && $this->freeKassaPolicyList($credentials,
                FreeKassaCallbackSecurity::SENDERS_KEY, false) === []) {
                return ['state' => 'invalid', 'matched_currencies' => []];
            }
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
            // Admin-only sandbox probes pay a nominal amount; no player coin rate is needed.
            if ($code === 'freekassa' && $row->mode === 'test') {
                return ['state' => $row->enabled ? 'local_ok' : 'disabled', 'matched_currencies' => $currencies];
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

    /**
     * @param  array<string,string>  $stored
     * @return list<array{secret:string,rotated_at:string,max_preparation_id:int,expires_at:string}>
     */
    private function readRetiredFreeKassaKeys(array $stored): array
    {
        return $this->readRetiredKeys($stored, self::FREEKASSA_RETIRED_KEYS);
    }

    private function readRetiredYooMoneyKeys(array $stored): array
    {
        return $this->readRetiredKeys($stored, self::YOOMONEY_RETIRED_KEYS);
    }

    private function readRetiredKeys(array $stored, string $field): array
    {
        if (! isset($stored[$field])) {
            return [];
        }
        try {
            $values = json_decode($stored[$field], true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($values) || count($values) > 4) {
                throw new RuntimeException('Invalid retained payment secrets.');
            }
            foreach ($values as $entry) {
                if (! is_array($entry) || ! is_string($entry['secret'] ?? null)
                    || strlen($entry['secret']) < 8 || strlen($entry['secret']) > 1024
                    || ! is_string($entry['rotated_at'] ?? null)
                    || ! is_int($entry['max_preparation_id'] ?? null)
                    || $entry['max_preparation_id'] < 0
                    || ! is_string($entry['expires_at'] ?? null)) {
                    throw new RuntimeException('Invalid retained payment secrets.');
                }
                CarbonImmutable::parse($entry['rotated_at']);
                CarbonImmutable::parse($entry['expires_at']);
            }

            return array_values($values);
        } catch (Throwable) {
            throw new RuntimeException('Unable to read protected payment key history.');
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
