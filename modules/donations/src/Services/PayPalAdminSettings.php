<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Data\PayPalApiCredentials;
use KaevCMS\Modules\Donations\Models\DonationProviderSetting;
use KaevCMS\Modules\Donations\Models\DonationSetting;
use RuntimeException;
use Throwable;

/** PayPal-only admin configuration; deliberately not registered for player checkout in I3. */
final class PayPalAdminSettings
{
    // Exact PayPal checkout subset, not the larger ISO 4217 list of the CMS.
    private const PAYPAL_CURRENCIES = [
        'AUD', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF',
        'ILS', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'SGD',
        'THB', 'TWD', 'USD',
    ];

    public function __construct(private readonly DonationCurrencyRegistry $currencyRegistry, private readonly AuditLogger $audit) {}

    /** @return list<string> */
    public function supportedCurrencies(): array
    {
        return array_values(array_filter(self::PAYPAL_CURRENCIES, function (string $currency): bool {
            $info = $this->currencyRegistry->find($currency);
            if ($info === null) {
                return false;
            }
            try {
                PayPalMoney::assertExponent($currency, (int) $info['exponent']);
                return true;
            } catch (InvalidArgumentException) {
                return false;
            }
        }));
    }

    /** Does not include plaintext Client Secret or Webhook ID. */
    public function state(): array
    {
        $row = DonationProviderSetting::query()->find('paypal');
        $credentials = $row ? $this->decrypt($row) : [];
        return [
            'currency_codes' => array_values(array_intersect($row?->currency_codes ?? [], $this->supportedCurrencies())),
            'sandbox_client_id' => $credentials['sandbox_client_id'] ?? '',
            'live_client_id' => $credentials['live_client_id'] ?? '',
            'sandbox_secret_saved' => isset($credentials['sandbox_client_secret']),
            'live_secret_saved' => isset($credentials['live_client_secret']),
            'sandbox_webhook_saved' => isset($credentials['sandbox_webhook_id']),
            'live_webhook_saved' => isset($credentials['live_webhook_id']),
            // IMPORTANT: provider activation remains off throughout iteration 3.
            'enabled' => false,
        ];
    }

    /** @param list<mixed> $currencies @param array<string,mixed> $inputs */
    public function save(Admin $admin, array $currencies, array $inputs): void
    {
        $this->authorize($admin);
        $allowed = $this->supportedCurrencies();
        if (count($currencies) > count($allowed)) {
            throw new InvalidArgumentException('Invalid PayPal currencies.');
        }
        foreach ($currencies as $currency) {
            if (! is_string($currency) || ! in_array($currency, $allowed, true)) {
                throw new InvalidArgumentException('Invalid PayPal currency.');
            }
        }
        if (count($currencies) !== count(array_unique($currencies))) {
            throw new InvalidArgumentException('Duplicate PayPal currency.');
        }
        $fields = ['sandbox_client_id', 'sandbox_client_secret', 'sandbox_webhook_id',
            'live_client_id', 'live_client_secret', 'live_webhook_id'];
        if (array_diff(array_keys($inputs), $fields) !== []) {
            throw new InvalidArgumentException('Unexpected PayPal field.');
        }
        foreach ($inputs as $key => $value) {
            if ($value !== null && (! is_string($value) || strlen($value) > 1024)) {
                throw new InvalidArgumentException('Invalid PayPal configuration.');
            }
            $inputs[$key] = $value ?? '';
            if ($inputs[$key] !== '' && ! $this->validField($key, $inputs[$key])) {
                throw new InvalidArgumentException('Invalid PayPal configuration.');
            }
        }
        DB::transaction(function () use ($admin, $currencies, $inputs): void {
            // The singleton serializes first insert, preventing lost secret rotations.
            DonationSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $row = DonationProviderSetting::query()->whereKey('paypal')->lockForUpdate()->first();
            if ($row === null) {
                $row = new DonationProviderSetting;
                $row->forceFill(['provider_code' => 'paypal', 'enabled' => false, 'mode' => 'test',
                    'currency_codes' => [], 'credentials_ciphertext' => null])->save();
            }
            $stored = $this->decrypt($row);
            foreach ($inputs as $key => $value) {
                if ($value !== '') {
                    $stored[$key] = $value;
                }
            }
            $row->forceFill([
                'enabled' => false, 'mode' => 'test', 'currency_codes' => $currencies,
                'credentials_ciphertext' => $stored === [] ? null : Crypt::encryptString(json_encode($stored, JSON_THROW_ON_ERROR)),
                'updated_by_admin_id' => $admin->getKey(),
            ])->save();
            if ($this->audit->success('module', 'donations.paypal_settings_saved', $admin,
                'donations-paypal', ['currencies' => $currencies, 'sandbox_configured' => $this->isConfigured($stored, 'sandbox'),
                    'live_configured' => $this->isConfigured($stored, 'live')]) === null) {
                throw new RuntimeException('Unable to audit PayPal configuration.');
            }
        }, 3);
    }

    /** Credential values may only be returned to the internal HTTP client. */
    public function credentials(string $environment): PayPalApiCredentials
    {
        if (! in_array($environment, ['sandbox', 'live'], true)) {
            throw new InvalidArgumentException('Invalid PayPal environment.');
        }
        $row = DonationProviderSetting::query()->find('paypal');
        $stored = $row === null ? [] : $this->decrypt($row);
        $id = $stored[$environment.'_client_id'] ?? '';
        $secret = $stored[$environment.'_client_secret'] ?? '';
        if (! $this->validField('client_id', $id) || ! $this->validField('client_secret', $secret)) {
            throw new InvalidArgumentException('PayPal credentials are not configured.');
        }
        return new PayPalApiCredentials($environment, $id, $secret);
    }

    public function webhookId(string $environment): ?string
    {
        if (! in_array($environment, ['sandbox', 'live'], true)) {
            throw new InvalidArgumentException('Invalid PayPal environment.');
        }
        $row = DonationProviderSetting::query()->find('paypal');
        $stored = $row === null ? [] : $this->decrypt($row);
        return $stored[$environment.'_webhook_id'] ?? null;
    }

    public function selectedCurrency(string $currency): int
    {
        $row = DonationProviderSetting::query()->find('paypal');
        if ($row === null || ! in_array($currency, $row->currency_codes ?? [], true)
            || ! in_array($currency, $this->supportedCurrencies(), true)) {
            throw new InvalidArgumentException('PayPal currency not enabled.');
        }
        return (int) $this->currencyRegistry->find($currency)['exponent'];
    }

    private function isConfigured(array $stored, string $environment): bool
    {
        return ! empty($stored[$environment.'_client_id']) && ! empty($stored[$environment.'_client_secret']);
    }

    private function validField(string $field, string $value): bool
    {
        if (str_ends_with($field, 'client_id') || $field === 'client_id') {
            return preg_match('/\A[A-Za-z0-9_-]{1,512}\z/D', $value) === 1;
        }
        if (str_ends_with($field, 'webhook_id')) {
            return preg_match('/\A[A-Za-z0-9_-]{1,190}\z/D', $value) === 1;
        }
        return preg_match('/\A[!-~]{1,1024}\z/D', $value) === 1;
    }

    private function authorize(Admin $admin): void
    {
        if (! in_array($admin->role, [AdminRole::Owner, AdminRole::Administrator], true)) {
            throw new AuthorizationException;
        }
    }

    /** @return array<string,string> */
    private function decrypt(DonationProviderSetting $row): array
    {
        if ($row->credentials_ciphertext === null) {
            return [];
        }
        try {
            $decoded = json_decode(Crypt::decryptString($row->credentials_ciphertext), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                throw new RuntimeException('Invalid PayPal settings.');
            }
            foreach ($decoded as $key => $value) {
                if (! is_string($key) || ! is_string($value)) {
                    throw new RuntimeException('Invalid PayPal settings.');
                }
            }
            return $decoded;
        } catch (Throwable) {
            throw new RuntimeException('Protected PayPal configuration unavailable.');
        }
    }
}
