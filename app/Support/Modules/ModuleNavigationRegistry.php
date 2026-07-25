<?php

namespace App\Support\Modules;

use Illuminate\Support\Facades\Route;
use InvalidArgumentException;

final class ModuleNavigationRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $accountLinks = [];

    /** @var array<string, array<string, mixed>> */
    private array $adminLinks = [];

    public function registerAccountLink(
        string $moduleId,
        string $routeName,
        string $labelKey,
        string $descriptionKey,
        int $sortOrder = 100,
    ): void {
        $this->accountLinks[$moduleId] = $this->validatedLink(
            moduleId: $moduleId,
            routeName: $routeName,
            labelKey: $labelKey,
            descriptionKey: $descriptionKey,
            sortOrder: $sortOrder,
            routePrefix: 'modules.'.$moduleId.'.',
        );
    }

    public function registerAdminLink(
        string $moduleId,
        string $routeName,
        string $labelKey,
        string $descriptionKey,
        int $sortOrder = 100,
    ): void {
        $this->adminLinks[$moduleId] = $this->validatedLink(
            moduleId: $moduleId,
            routeName: $routeName,
            labelKey: $labelKey,
            descriptionKey: $descriptionKey,
            sortOrder: $sortOrder,
            routePrefix: 'admin.module-pages.'.$moduleId.'.',
        );
    }

    /** @return list<array{module_id:string,route:string,label_key:string,description_key:string,sort_order:int}> */
    public function accountLinks(): array
    {
        return $this->availableLinks($this->accountLinks);
    }

    /** @return list<array{module_id:string,route:string,label_key:string,description_key:string,sort_order:int}> */
    public function adminLinks(): array
    {
        return $this->availableLinks($this->adminLinks);
    }

    /**
     * @param  array<string, array<string, mixed>>  $links
     * @return list<array{module_id:string,route:string,label_key:string,description_key:string,sort_order:int}>
     */
    private function availableLinks(array $links): array
    {
        $resolved = array_values(array_filter(
            $links,
            static fn (array $link): bool => Route::has((string) $link['route']),
        ));

        usort($resolved, static function (array $left, array $right): int {
            $order = ((int) $left['sort_order']) <=> ((int) $right['sort_order']);

            return $order !== 0
                ? $order
                : strcasecmp((string) $left['module_id'], (string) $right['module_id']);
        });

        /** @var list<array{module_id:string,route:string,label_key:string,description_key:string,sort_order:int}> $resolved */
        return $resolved;
    }

    /** @return array{module_id:string,route:string,label_key:string,description_key:string,sort_order:int} */
    private function validatedLink(
        string $moduleId,
        string $routeName,
        string $labelKey,
        string $descriptionKey,
        int $sortOrder,
        string $routePrefix,
    ): array {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,99}\z/', $moduleId) !== 1) {
            throw new InvalidArgumentException('Module navigation identifier is invalid.');
        }

        if (! str_starts_with($routeName, $routePrefix)) {
            throw new InvalidArgumentException('Module navigation route is outside its module namespace.');
        }

        if (trim($labelKey) === '' || trim($descriptionKey) === '') {
            throw new InvalidArgumentException('Module navigation translation keys are required.');
        }

        return [
            'module_id' => $moduleId,
            'route' => $routeName,
            'label_key' => trim($labelKey),
            'description_key' => trim($descriptionKey),
            'sort_order' => max(0, min(100000, $sortOrder)),
        ];
    }
}
