<?php

namespace App\Services\Pages;

use App\Models\CmsSetting;
use App\Models\Page;
use Illuminate\Support\Facades\DB;

/**
 * The "About the server" page: an ordinary CMS page found by its fixed address. /about shows
 * its text above the game server cards; without a live page /about shows the cards alone.
 */
final class AboutPages
{
    public const KEY = 'content.about_initialized';

    public const SLUG = 'about';

    /** false = not read yet in this request. */
    private Page|false|null $selected = false;

    public function selectedId(): ?int
    {
        return $this->selected()?->id;
    }

    public function selected(): ?Page
    {
        if ($this->selected === false) {
            $this->selected = Page::query()->with('translations')->where('slug', self::SLUG)->first();
        }

        return $this->selected;
    }

    /** Page and translation writes call this, so a changed page is read again. */
    public function forget(): void
    {
        $this->selected = false;
    }

    /** Install once. An intentionally deleted page is never recreated by an update. */
    public function initialize(): void
    {
        DB::transaction(function (): void {
            $inserted = CmsSetting::query()->insertOrIgnore([
                'key' => self::KEY, 'value' => '1', 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($inserted === 1 && ! Page::withTrashed()->where('slug', self::SLUG)->exists()
                && ! DB::table('page_translations')->where('slug', self::SLUG)->exists()) {
                $page = Page::query()->create([
                    'slug' => self::SLUG, 'is_published' => true,
                    'show_in_header' => false, 'show_in_footer' => false, 'sort_order' => 90,
                ]);
                foreach ($this->templateTranslations() as $translation) {
                    $page->translations()->create($translation);
                }
            }
        });
    }

    /** @return array<string, array{locale:string,slug:string,title:string,body:string,seo_title:string,seo_description:string}> */
    public function templateTranslations(): array
    {
        $translations = [];
        foreach ([
            'ru' => ['О сервере', '<p>Мы создали этот мир для тех, кто ценит классическую Lineage II: честную игру, живое сообщество и стабильную работу сервера.</p><p>Ниже — основные параметры игровых серверов. Правила, новости и ответы на частые вопросы — в разделах сайта.</p>'],
            'en' => ['About the server', '<p>We built this world for players who value classic Lineage II: fair play, a living community and a stable server.</p><p>The main game server details are below. Rules, news and answers to common questions are in the other sections of the site.</p>'],
        ] as $locale => [$title, $body]) {
            $translations[$locale] = ['locale' => $locale, 'slug' => self::SLUG, 'title' => $title, 'body' => $body, 'seo_title' => '', 'seo_description' => ''];
        }

        return $translations;
    }
}
