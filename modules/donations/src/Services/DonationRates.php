<?php

namespace KaevCMS\Modules\Donations\Services;

use App\Auth\AdminRole;
use App\Models\Admin;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use KaevCMS\Modules\Donations\Models\DonationCurrencyRate;
use KaevCMS\Modules\Donations\Models\DonationSetting;
use RuntimeException;

/** Currency prices live exclusively in Donations, never in the core wallet. */
final class DonationRates
{
    public function __construct(
        private readonly DonationCurrencyRegistry $registry,
        private readonly DonationMoney $money,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{min_coins:int,max_coins:int} */
    public function limits(): array
    {
        $settings = DonationSetting::query()->find(1);
        if (! $settings instanceof DonationSetting) {
            throw new RuntimeException('Donations settings are missing.');
        }
        $min = (int) $settings->getRawOriginal('min_coins');
        $max = (int) $settings->getRawOriginal('max_coins');
        if ($min < 1 || $max < $min || $max > DonationMoney::MAX_COINS) {
            throw new RuntimeException('Donations coin limits are invalid.');
        }

        return ['min_coins' => $min, 'max_coins' => $max];
    }

    /** @return array<int, DonationCurrencyRate> */
    public function configured(): array
    {
        return DonationCurrencyRate::query()->orderBy('currency_code')->get()->all();
    }

    /**
     * Only configured, enabled, internally consistent currencies may be quoted to players.
     * A provider is still required before these currencies can be used for real payments.
     *
     * @return list<array{code:string,name:string,symbol:string,exponent:int,unit_price_minor:int}>
     */
    public function availableForPlayers(string $locale): array
    {
        $result = [];
        foreach (DonationCurrencyRate::query()->where('enabled', true)->orderBy('currency_code')->get() as $rate) {
            $code = (string) $rate->currency_code;
            $currency = $this->registry->find($code, $locale);
            $minor = (int) $rate->unit_price_minor;
            if ($currency === null || (int) $rate->exponent !== $currency['exponent']
                || $minor < 1 || $minor > DonationMoney::MAX_UNIT_PRICE_MINOR) {
                continue;
            }
            $result[] = [
                'code' => $code,
                'name' => $currency['name'],
                'symbol' => $currency['symbol'],
                'exponent' => $currency['exponent'],
                'unit_price_minor' => $minor,
            ];
        }

        return $result;
    }

    public function saveLimits(int $min, int $max, Admin $admin): void
    {
        $this->authorize($admin);
        if ($min < 1 || $max < $min || $max > DonationMoney::MAX_COINS) {
            throw ValidationException::withMessages(['max_coins' => __('module-donations::messages.invalid_limits')]);
        }
        DB::transaction(function () use ($min, $max, $admin): void {
            $settings = DonationSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $settings->forceFill(['min_coins' => $min, 'max_coins' => $max, 'updated_by_admin_id' => $admin->getKey()])->save();
            $this->auditOrFail('donations.limits_updated', $admin, [
                'min_coins' => $min, 'max_coins' => $max,
            ]);
        });
    }

    public function saveRate(string $code, string $price, bool $enabled, Admin $admin): void
    {
        $this->authorize($admin);
        $currency = $this->registry->find($code);
        if ($currency === null) {
            throw ValidationException::withMessages(['currency_code' => __('module-donations::messages.invalid_currency')]);
        }
        try {
            $minor = $this->money->parseUnitPrice($price, $currency['exponent']);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['unit_price' => __('module-donations::messages.invalid_price')]);
        }
        DB::transaction(function () use ($currency, $minor, $enabled, $admin): void {
            // Lock one stable singleton to serialize first inserts and concurrent updates.
            DonationSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $rate = DonationCurrencyRate::query()->whereKey($currency['code'])->lockForUpdate()->first();
            if (! $rate instanceof DonationCurrencyRate) {
                $rate = new DonationCurrencyRate;
                $rate->currency_code = $currency['code'];
            } elseif ((int) $rate->exponent !== $currency['exponent']) {
                // A price of a pre-existing currency must never be silently reinterpreted.
                throw new RuntimeException('Stored currency precision does not match the registry.');
            }
            $rate->forceFill([
                'exponent' => $currency['exponent'],
                'unit_price_minor' => $minor,
                'enabled' => $enabled,
                'updated_by_admin_id' => $admin->getKey(),
            ])->save();
            $this->auditOrFail('donations.rate_updated', $admin, [
                'currency_code' => $currency['code'],
                'exponent' => $currency['exponent'],
                'unit_price_minor' => $minor,
                'enabled' => $enabled,
            ]);
        });
    }

    /** @return array{currency_code:string,coins:int,unit_price_minor:int,total_minor:int,exponent:int} */
    public function quote(string $code, int $coins): array
    {
        $limits = $this->limits();
        if ($coins < $limits['min_coins'] || $coins > $limits['max_coins']) {
            throw new \InvalidArgumentException('Requested coin quantity is outside the configured bounds.');
        }
        $rate = DonationCurrencyRate::query()->find($code);
        $currency = $this->registry->find($code);
        if (! $rate instanceof DonationCurrencyRate || ! in_array($rate->getRawOriginal('enabled'), [true, 1, '1'], true)
            || $currency === null || $currency['exponent'] !== (int) $rate->getRawOriginal('exponent')) {
            throw new \InvalidArgumentException('Currency is not enabled or supported.');
        }
        $minor = (int) $rate->unit_price_minor;

        return [
            'currency_code' => $code, 'coins' => $coins, 'unit_price_minor' => $minor,
            'total_minor' => $this->money->totalMinor($minor, $coins), 'exponent' => $currency['exponent'],
        ];
    }

    private function authorize(Admin $admin): void
    {
        if (! in_array($admin->role, [AdminRole::Owner, AdminRole::Administrator], true)) {
            throw new AuthorizationException;
        }
    }

    /** @param array<string, int|string|bool> $details */
    private function auditOrFail(string $action, Admin $admin, array $details): void
    {
        if ($this->audit->success(category: 'module', action: $action, actor: $admin, target: 'donations', details: $details) === null) {
            throw new RuntimeException('Unable to audit Donations tariff settings.');
        }
    }
}
