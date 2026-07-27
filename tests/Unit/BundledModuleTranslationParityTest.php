<?php

namespace Tests\Unit;

use App\Support\Rewards\RewardTransferFailure;
use KaevCMS\Modules\DailyRewards\Exceptions\DailyRewardClaimFailure;
use KaevCMS\Modules\PromoCodes\Exceptions\PromoCodeActivationFailure;
use Tests\TestCase;

class BundledModuleTranslationParityTest extends TestCase
{
    public function test_daily_rewards_russian_and_english_keys_match(): void
    {
        require_once base_path('modules/daily-rewards/src/Exceptions/DailyRewardClaimFailure.php');

        $this->assertTranslationParity(
            base_path('modules/daily-rewards/lang/en/messages.php'),
            base_path('modules/daily-rewards/lang/ru/messages.php'),
        );

        $english = $this->flatten($this->translations('modules/daily-rewards/lang/en/messages.php'));
        foreach (DailyRewardClaimFailure::cases() as $failure) {
            $key = str_replace('module-daily-rewards::messages.', '', $failure->translationKey());
            $this->assertArrayHasKey($key, $english);
        }
    }

    public function test_promo_codes_russian_and_english_keys_match(): void
    {
        require_once base_path('modules/promo-codes/src/Exceptions/PromoCodeActivationFailure.php');

        $this->assertTranslationParity(
            base_path('modules/promo-codes/lang/en/messages.php'),
            base_path('modules/promo-codes/lang/ru/messages.php'),
        );

        $english = $this->flatten($this->translations('modules/promo-codes/lang/en/messages.php'));
        foreach (PromoCodeActivationFailure::cases() as $failure) {
            $key = str_replace('module-promo-codes::messages.', '', $failure->translationKey());
            $this->assertArrayHasKey($key, $english);
        }
    }

    public function test_reward_transfer_failures_use_stable_translation_keys(): void
    {
        $this->assertTranslationParity(
            lang_path('en/rewards.php'),
            lang_path('ru/rewards.php'),
        );

        $english = $this->flatten($this->translations('lang/en/rewards.php'));
        foreach (RewardTransferFailure::cases() as $failure) {
            $key = str_replace('rewards.', '', $failure->translationKey());
            $this->assertArrayHasKey($key, $english);
        }
    }

    private function assertTranslationParity(string $englishPath, string $russianPath): void
    {
        $english = array_keys($this->flatten($this->loadTranslationFile($englishPath)));
        $russian = array_keys($this->flatten($this->loadTranslationFile($russianPath)));
        sort($english);
        sort($russian);

        $this->assertSame($english, $russian);
    }

    /** @return array<string, mixed> */
    private function translations(string $relativePath): array
    {
        return $this->loadTranslationFile(base_path($relativePath));
    }

    /** @return array<string, mixed> */
    private function loadTranslationFile(string $path): array
    {
        $translations = require $path;
        $this->assertIsArray($translations);

        return $translations;
    }

    /**
     * @param  array<string, mixed>  $translations
     * @return array<string, string>
     */
    private function flatten(array $translations, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($translations as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                $flattened += $this->flatten($value, $fullKey);

                continue;
            }

            $this->assertIsString($value, "Translation [{$fullKey}] must be a string.");
            $flattened[$fullKey] = $value;
        }

        return $flattened;
    }
}
