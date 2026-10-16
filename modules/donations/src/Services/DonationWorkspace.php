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

/** One atomic administration form; orders keep their own immutable price snapshots. */
final class DonationWorkspace
{
    public function __construct(
        private readonly DonationMoney $money,
        private readonly DonationCurrencyRegistry $currencies,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{show_in_account:bool,min_coins:int,max_coins:int,rates:list<DonationCurrencyRate>,snapshot:string} */
    public function read(): array
    {
        return DB::transaction(function (): array {
            $settings = DonationSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $rates = DonationCurrencyRate::query()->orderBy('currency_code')->get()->all();

            return [
                'show_in_account' => in_array($settings->getRawOriginal('show_in_account'), [true, 1, '1'], true),
                'min_coins' => (int) $settings->min_coins,
                'max_coins' => (int) $settings->max_coins,
                'rates' => $rates,
                'snapshot' => $this->snapshot($settings, $rates),
            ];
        });
    }

    /** @param array{snapshot:string,show_in_account:bool|int|string,min_coins:int|string,max_coins:int|string,rates?:array<string,array{remove:bool|int|string,unit_price?:string,enabled?:bool|int|string}>} $input */
    public function save(array $input, Admin $admin): void
    {
        if (! $admin->exists || ! $admin->is_active || ! in_array($admin->role, [AdminRole::Owner, AdminRole::Administrator], true)) {
            throw new AuthorizationException;
        }
        $min = (int) $input['min_coins'];
        $max = (int) $input['max_coins'];
        if ($min < 1 || $max < $min || $max > DonationMoney::MAX_COINS) {
            throw ValidationException::withMessages(['max_coins' => __('module-donations::messages.invalid_limits')]);
        }
        $prepared = [];
        foreach ($input['rates'] ?? [] as $code => $row) {
            $currency = $this->currencies->find($code);
            if ($currency === null) {
                throw ValidationException::withMessages(['rates' => __('module-donations::messages.invalid_currency')]);
            }
            $remove = (bool) $row['remove'];
            $minor = null;
            if (! $remove) {
                try {
                    $minor = $this->money->parseUnitPrice($row['unit_price'] ?? '', $currency['exponent']);
                } catch (\InvalidArgumentException) {
                    throw ValidationException::withMessages(['rates.'.$code.'.unit_price' => __('module-donations::messages.invalid_price')]);
                }
            }
            $prepared[$code] = ['remove' => $remove, 'minor' => $minor, 'exponent' => $currency['exponent'], 'enabled' => (bool) ($row['enabled'] ?? false)];
        }

        DB::transaction(function () use ($input, $min, $max, $prepared, $admin): void {
            // All existing tariff/visibility writers and draft creation lock this singleton first.
            $settings = DonationSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $rates = DonationCurrencyRate::query()->orderBy('currency_code')->lockForUpdate()->get();
            if (! hash_equals($this->snapshot($settings, $rates->all()), $input['snapshot'])) {
                throw ValidationException::withMessages(['snapshot' => __('module-donations::messages.workspace_stale')]);
            }
            $changes = [];
            foreach ($prepared as $code => $row) {
                $rate = $rates->firstWhere('currency_code', $code);
                if ($row['remove']) {
                    if ($rate instanceof DonationCurrencyRate) {
                        $changes[$code] = ['before' => $rate->only(['unit_price_minor', 'exponent', 'enabled']), 'after' => null];
                        $rate->delete();
                    }

                    continue;
                }
                if ($rate instanceof DonationCurrencyRate && $rate->exponent !== $row['exponent']) {
                    throw new RuntimeException('Stored currency precision does not match the registry.');
                }
                $before = $rate?->only(['unit_price_minor', 'exponent', 'enabled']);
                $rate ??= new DonationCurrencyRate;
                $rate->forceFill(['currency_code' => $code, 'unit_price_minor' => $row['minor'], 'exponent' => $row['exponent'], 'enabled' => $row['enabled']]);
                if (! $rate->isDirty()) {
                    continue;
                }
                $rate->forceFill(['updated_by_admin_id' => $admin->getKey()])->save();
                $changes[$code] = ['before' => $before, 'after' => $rate->only(['unit_price_minor', 'exponent', 'enabled'])];
            }
            $beforeSettings = $settings->only(['show_in_account', 'min_coins', 'max_coins']);
            $settings->forceFill(['show_in_account' => (bool) $input['show_in_account'], 'min_coins' => $min, 'max_coins' => $max]);
            if (! $settings->isDirty() && $changes === []) {
                return;
            }
            $settings->forceFill(['updated_by_admin_id' => $admin->getKey()])->save();
            if ($this->audit->success(category: 'module', action: 'donations.workspace_updated', actor: $admin, target: 'donations', details: [
                'settings_before' => $beforeSettings,
                'settings_after' => $settings->only(['show_in_account', 'min_coins', 'max_coins']),
                'currencies' => $changes,
            ]) === null) {
                throw new RuntimeException('Unable to audit Donations settings.');
            }
        });
    }

    /** @param list<DonationCurrencyRate> $rates */
    private function snapshot(DonationSetting $settings, array $rates): string
    {
        return hash('sha256', json_encode([
            $settings->only(['show_in_account', 'min_coins', 'max_coins']),
            array_map(static fn (DonationCurrencyRate $rate): array => $rate->only(['currency_code', 'exponent', 'unit_price_minor', 'enabled']), $rates),
        ], JSON_THROW_ON_ERROR));
    }
}
