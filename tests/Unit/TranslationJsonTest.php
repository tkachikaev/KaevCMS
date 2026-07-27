<?php

namespace Tests\Unit;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TranslationJsonTest extends TestCase
{
    /** @return array<string,array{string}> */
    public static function builtInLocales(): array
    {
        return [
            'Russian' => ['ru'],
            'English' => ['en'],
        ];
    }

    /** @throws JsonException */
    #[DataProvider('builtInLocales')]
    public function test_built_in_json_translations_have_no_case_insensitive_duplicate_keys(string $locale): void
    {
        $content = file_get_contents(lang_path($locale.'.json'));
        $this->assertIsString($content);

        /** @var array<string,string> $translations */
        $translations = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $normalized = [];

        foreach (array_keys($translations) as $key) {
            $caseInsensitiveKey = mb_strtolower($key, 'UTF-8');

            if (array_key_exists($caseInsensitiveKey, $normalized)) {
                $this->fail("Case-insensitive duplicate translation keys: {$normalized[$caseInsensitiveKey]} and {$key}");
            }

            $normalized[$caseInsensitiveKey] = $key;
        }
    }

    /** @throws JsonException */
    public function test_built_in_translation_catalogs_have_matching_keys_and_placeholders(): void
    {
        /** @var array<string,string> $english */
        $english = json_decode((string) file_get_contents(lang_path('en.json')), true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string,string> $russian */
        $russian = json_decode((string) file_get_contents(lang_path('ru.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(array_keys($english), array_keys($russian));

        foreach ($english as $key => $value) {
            preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', $value, $englishMatches);
            preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', $russian[$key], $russianMatches);

            $englishPlaceholders = array_values(array_unique($englishMatches[0]));
            $russianPlaceholders = array_values(array_unique($russianMatches[0]));
            sort($englishPlaceholders);
            sort($russianPlaceholders);

            $this->assertSame(
                $englishPlaceholders,
                $russianPlaceholders,
                "Translation placeholders differ for key: {$key}",
            );
        }
    }

    /** @throws JsonException */
    public function test_literal_translation_keys_used_by_the_application_exist_in_built_in_locales(): void
    {
        /** @var array<string,string> $english */
        $english = json_decode((string) file_get_contents(lang_path('en.json')), true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string,string> $russian */
        $russian = json_decode((string) file_get_contents(lang_path('ru.json')), true, 512, JSON_THROW_ON_ERROR);
        $catalogs = ['en' => $english, 'ru' => $russian];
        $directories = [
            app_path(),
            resource_path('views'),
            base_path('routes'),
            base_path('themes/default'),
            base_path('themes/kaev-aurelia'),
            base_path('account-themes/luxury'),
            base_path('account-themes/kaev-aurelia'),
        ];
        $keys = [];

        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            foreach (File::allFiles($directory) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $content = $file->getContents();
                preg_match_all('/(?:__|@lang|trans)\(\s*([\'\"])(.*?)\1/s', $content, $matches);

                foreach ($matches[2] as $key) {
                    if ($key === ''
                        || str_contains($key, '$')
                        || str_contains($key, '::')
                        || str_contains($key, '\\')) {
                        continue;
                    }

                    $keys[$key] = true;
                }
            }
        }

        foreach (array_keys($keys) as $key) {
            foreach ($catalogs as $locale => $translations) {
                $this->assertTrue(
                    $this->literalTranslationExists($locale, $translations, $key),
                    "Missing {$locale} translation for literal key: {$key}",
                );
            }
        }
    }

    /** @param array<string,string> $jsonTranslations */
    private function literalTranslationExists(string $locale, array $jsonTranslations, string $key): bool
    {
        if (array_key_exists($key, $jsonTranslations)) {
            return true;
        }

        $segments = explode('.', $key, 2);
        if (count($segments) !== 2) {
            return false;
        }

        [$group, $nestedKey] = $segments;
        $groupPath = lang_path($locale.'/'.$group.'.php');
        if (! is_file($groupPath)) {
            return false;
        }

        $groupTranslations = require $groupPath;

        return is_array($groupTranslations) && Arr::has($groupTranslations, $nestedKey);
    }
}
