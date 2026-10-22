<?php

namespace App\Services\Navigation;

use App\Models\NavigationLink;
use App\Support\Database\TableExistence;
use Illuminate\Support\Collection;
use Throwable;

final class NavigationLinks
{
    /** @return Collection<int, NavigationLink> */
    public function header(): Collection
    {
        return $this->forLocation('show_in_header');
    }

    /** @return Collection<int, NavigationLink> */
    public function footer(): Collection
    {
        return $this->forLocation('show_in_footer');
    }

    /** @return Collection<int, NavigationLink> */
    private function forLocation(string $column): Collection
    {
        try {
            if (! app(TableExistence::class)->has('navigation_links') || ! app(TableExistence::class)->has('navigation_link_translations')) {
                return collect();
            }

            return NavigationLink::query()
                ->with('translations')
                ->where('is_active', true)
                ->where($column, true)
                ->ordered()
                ->get()
                ->filter(fn (NavigationLink $link): bool => NavigationLink::isSafeUrl($link->url) && $link->titleFor() !== '')
                ->values();
        } catch (Throwable) {
            // An uninstalled or not-yet-migrated CMS must still render the public theme.
            return collect();
        }
    }
}
