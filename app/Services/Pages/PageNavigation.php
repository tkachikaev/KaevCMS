<?php

namespace App\Services\Pages;

use App\Models\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class PageNavigation
{
    /** @return Collection<int, Page> */
    public function header(): Collection
    {
        return $this->forLocation('show_in_header');
    }

    /** @return Collection<int, Page> */
    public function footer(): Collection
    {
        return $this->forLocation('show_in_footer');
    }

    /** @return Collection<int, Page> */
    private function forLocation(string $column): Collection
    {
        try {
            if (! Schema::hasTable('pages') || ! Schema::hasTable('page_translations')) {
                return collect();
            }

            return Page::query()
                ->with('translations')
                ->published()
                ->where($column, true)
                ->ordered()
                ->get()
                ->filter(fn (Page $page): bool => $page->translation() !== null)
                ->values();
        } catch (Throwable) {
            return collect();
        }
    }
}
