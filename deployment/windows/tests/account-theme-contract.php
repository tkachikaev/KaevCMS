<?php

declare(strict_types=1);

namespace App\Services {
    final class CmsSettings
    {
        public function get(string $key, ?string $default = null): ?string
        {
            return $default;
        }

        public function set(string $key, string $value): void {}
    }
}

namespace Illuminate\Filesystem {
    final class Filesystem {}
}

namespace App\Support\Themes {
    function __(string $value): string
    {
        return $value;
    }

    final class ThemeValidator
    {
        /** @var array<string, mixed> */
        public array $arguments = [];

        /**
         * @param  array<int, string>  $requiredFiles
         * @param  array<int, string>  $requiredPublicFiles
         * @return array<string, mixed>
         */
        public function inspect(
            string $slug,
            string $themesPath,
            string $publicThemesPath,
            string $assetUrlPrefix,
            string $activeTheme,
            array $requiredFiles,
            array $requiredPublicFiles = [],
            ?string $missingPublicAssetsMessage = null,
        ): array {
            $this->arguments = compact(
                'slug',
                'themesPath',
                'publicThemesPath',
                'assetUrlPrefix',
                'activeTheme',
                'requiredFiles',
                'requiredPublicFiles',
                'missingPublicAssetsMessage',
            );

            return [
                'slug' => $slug,
                'name' => $slug,
                'manifest' => [],
                'valid' => true,
                'compatible' => true,
                'active' => true,
            ];
        }
    }
}

namespace {
    use App\Services\CmsSettings;
    use App\Support\Themes\AccountThemeManager;
    use App\Support\Themes\ThemeValidator;
    use Illuminate\Filesystem\Filesystem;

    $root = dirname(__DIR__, 3);
    require $root.'/app/Support/Themes/AccountThemeManager.php';

    $validator = new ThemeValidator;
    $manager = new AccountThemeManager(
        themesPath: $root.'/account-themes',
        publicThemesPath: $root.'/public/account-themes',
        fallbackTheme: 'luxury',
        settings: new CmsSettings,
        files: new Filesystem,
        validator: $validator,
    );

    $manager->inspect('luxury');

    if (($validator->arguments['requiredPublicFiles'] ?? null) !== ['assets/css/app.css']) {
        throw new RuntimeException('Account themes must validate only theme-owned CSS assets.');
    }

    if (is_file($root.'/public/assets/account/css/components.css') === false) {
        throw new RuntimeException('The shared account component stylesheet is missing.');
    }

    if (is_file($root.'/public/assets/account/js/navigation.js') === false) {
        throw new RuntimeException('The shared account navigation runtime is missing.');
    }

    $sharedCss = file_get_contents($root.'/public/assets/account/css/components.css');
    if (! is_string($sharedCss) || ! str_contains($sharedCss, '.account-operation-modal{')) {
        throw new RuntimeException('The shared account component stylesheet does not contain the expected component contract.');
    }

    foreach (['luxury', 'kaev-aurelia'] as $theme) {
        if (is_file($root.'/public/account-themes/'.$theme.'/assets/js/navigation.js')) {
            throw new RuntimeException("Duplicated account navigation runtime remains in theme: {$theme}");
        }

        $layout = file_get_contents($root.'/account-themes/'.$theme.'/views/layouts/app.blade.php');
        if (! is_string($layout) || ! str_contains($layout, 'assets/account/css/components.css')) {
            throw new RuntimeException("Shared account component stylesheet is not loaded by theme: {$theme}");
        }
    }

    echo "Account theme shared-runtime contract checks completed successfully.\n";
}
