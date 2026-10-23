<?php

namespace App\Services\Pages;

use App\Models\CmsSetting;
use App\Models\Page;
use Illuminate\Support\Facades\DB;

/**
 * The example "About the server" page created on installation: an ordinary page with the
 * address "about", which the theme's About link opens. It can be edited or deleted freely.
 */
final class AboutPages
{
    public const KEY = 'content.about_initialized';

    public const SLUG = 'about';

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
            'ru' => ['О сервере', '<p>Мы создали этот мир для тех, кто ценит классическую Lineage II: честную игру, живое сообщество и стабильную работу сервера.</p><p>Расскажите здесь о хрониках, рейтах, правилах и событиях сервера.</p>'],
            'en' => ['About the server', '<p>We built this world for players who value classic Lineage II: fair play, a living community and a stable server.</p><p>Tell players about the chronicle, rates, rules and events of your server here.</p>'],
        ] as $locale => [$title, $body]) {
            $translations[$locale] = ['locale' => $locale, 'slug' => self::SLUG, 'title' => $title, 'body' => $body, 'seo_title' => '', 'seo_description' => ''];
        }

        return $translations;
    }
}
