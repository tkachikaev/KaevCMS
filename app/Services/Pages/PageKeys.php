<?php

namespace App\Services\Pages;

use App\Models\Page;

/**
 * Theme buttons link to a page by its address key (the default-language address): "about",
 * "downloads" or any other. A page created with that address is picked up at once; without
 * one the link still points at /pages/<key>, which answers "not found" until it exists.
 */
final class PageKeys
{
    /** @var array<string, Page|null> Pages read in this request, by key. */
    private array $pages = [];

    public function find(string $key): ?Page
    {
        if (! array_key_exists($key, $this->pages)) {
            $this->pages[$key] = Page::query()->with('translations')->where('slug', $key)->first();
        }

        return $this->pages[$key];
    }

    public function url(string $key, ?string $locale = null): string
    {
        $page = $this->find($key);
        if ($page !== null && $page->isLive()) {
            return page_url($page, $locale);
        }

        return route('pages.show', ['page' => $key]);
    }

    /** Page and translation writes call this, so a changed page is read again. */
    public function forget(): void
    {
        $this->pages = [];
    }
}
