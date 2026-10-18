<?php

namespace App\Services\Wallet;

use App\Services\CmsSettings;
use App\Services\Localization\LanguageManager;
use App\Services\Settings\SettingsImageStorage;

/** Branding of an internal, non-withdrawable virtual currency. No fiat pricing. */
final class WalletAppearanceSettings
{
    private const PREFIX = 'wallet.appearance.';

    public function __construct(
        private readonly CmsSettings $settings,
        private readonly LanguageManager $languages,
        private readonly SettingsImageStorage $images,
    ) {}

    /** @return array{symbol:string,icon:string|null,icon_url:string|null} */
    public function values(): array
    {
        $symbol = trim((string) $this->settings->get(self::PREFIX.'symbol', ''));
        $icon = $this->images->normalizePath($this->settings->get(self::PREFIX.'icon'), 'wallet-coin');

        return [
            'symbol' => mb_substr($symbol, 0, 16),
            'icon' => $icon,
            'icon_url' => $this->images->publicUrl($icon) ?? asset('assets/images/wallet-coin.webp'),
        ];
    }

    /** @return array<string,array{one:string,few:string,many:string}> */
    public function translations(): array
    {
        $result = [];
        foreach (array_unique(array_merge(['ru', 'en'], $this->languages->enabledCodes())) as $code) {
            $defaults = $this->defaults($code);
            $result[$code] = [];
            foreach (['one', 'few', 'many'] as $form) {
                $result[$code][$form] = trim((string) $this->settings->get(self::PREFIX.$code.'.'.$form, $defaults[$form]));
            }
        }

        return $result;
    }

    /** @return array{one:string,few:string,many:string} */
    public function forms(?string $locale = null): array
    {
        $locale = strtolower(str_replace('_', '-', $locale ?: app()->getLocale()));
        $locale = preg_replace('/-.*/', '', $locale) ?: 'ru';
        $fallback = $this->languages->default();
        $defaults = $this->defaults($locale);
        $forms = [];
        foreach (['one', 'few', 'many'] as $form) {
            $candidate = trim((string) $this->settings->get(self::PREFIX.$locale.'.'.$form, ''));
            if ($candidate === '' && ! in_array($locale, ['ru', 'en'], true) && $locale !== $fallback) {
                $candidate = trim((string) $this->settings->get(self::PREFIX.$fallback.'.'.$form, ''));
            }
            $forms[$form] = $candidate !== '' ? $candidate : $defaults[$form];
        }

        return $forms;
    }

    public function label(int $amount, ?string $locale = null): string
    {
        $forms = $this->forms($locale);
        $locale = strtolower(str_replace('_', '-', $locale ?: app()->getLocale()));
        if (str_starts_with($locale, 'ru')) {
            $lastTwo = $amount % 100;
            $last = $amount % 10;
            $form = $lastTwo >= 11 && $lastTwo <= 14 ? 'many'
                : ($last === 1 ? 'one' : (($last >= 2 && $last <= 4) ? 'few' : 'many'));
        } else {
            $form = $amount === 1 ? 'one' : 'many';
        }

        return $amount.' '.$forms[$form];
    }

    /** @param array<string,array{one:string,few:string,many:string}> $translations */
    public function update(string $symbol, ?string $icon, array $translations): void
    {
        $data = [
            self::PREFIX.'symbol' => trim($symbol),
            self::PREFIX.'icon' => $this->images->normalizePath($icon, 'wallet-coin'),
        ];
        $allowedLocales = array_unique(array_merge(['ru', 'en'], $this->languages->enabledCodes()));
        foreach ($translations as $locale => $forms) {
            if (! in_array($locale, $allowedLocales, true)) {
                continue;
            }
            foreach (['one', 'few', 'many'] as $form) {
                $data[self::PREFIX.$locale.'.'.$form] = trim($forms[$form]);
            }
        }
        $this->settings->setMany($data);
    }

    /** @return array{one:string,few:string,many:string} */
    private function defaults(string $locale): array
    {
        return $locale === 'ru'
            ? ['one' => 'монета', 'few' => 'монеты', 'many' => 'монет']
            : ['one' => 'coin', 'few' => 'coins', 'many' => 'coins'];
    }
}
