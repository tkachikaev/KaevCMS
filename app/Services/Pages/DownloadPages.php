<?php

namespace App\Services\Pages;

use App\Models\CmsSetting;
use App\Models\Page;
use Illuminate\Support\Facades\DB;

final class DownloadPages
{
    public const KEY = 'content.downloads_initialized';

    public const SLUG = 'downloads';

    public function selectedId(): ?int
    {
        return $this->selected()?->id;
    }

    public function selected(): ?Page
    {
        return Page::query()->with('translations')->where('slug', self::SLUG)->first();
    }

    /** Install once. An intentionally cleared or deleted page is never recreated by an update. */
    public function initialize(): void
    {
        DB::transaction(function (): void {
            $inserted = CmsSetting::query()->insertOrIgnore([
                'key' => self::KEY, 'value' => '1', 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($inserted === 1 && ! Page::withTrashed()->where('slug', self::SLUG)->exists()
                && ! DB::table('page_translations')->where('slug', self::SLUG)->exists()) {
                $this->createDefault();
            }
        });
    }

    /** Accept links only; the CMS never fetches or downloads the linked files itself. */
    public static function safeUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f]/', $value)
            || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($value);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $value;
    }

    private function createDefault(): Page
    {
        $page = Page::query()->create([
            'slug' => self::SLUG, 'is_published' => true,
            'show_in_header' => false, 'show_in_footer' => false, 'sort_order' => 100,
        ]);
        foreach ($this->templateTranslations() as $translation) {
            $page->translations()->create($translation);
        }

        return $page->load('translations');
    }

    /** @return array<string, array{locale:string,slug:string,title:string,body:string,seo_title:string,seo_description:string}> */
    public function templateTranslations(): array
    {
        $translations = [];
        foreach ([
            'ru' => ['Скачать игру', '<h2>Всё готово для начала приключения</h2><p>Установите клиент игры, затем распакуйте патч в его папку с заменой файлов. После установки запустите игру и войдите в свой игровой аккаунт.</p><p>Официальные ссылки на файлы появятся здесь, когда сервер будет готов к запуску.</p>'],
            'en' => ['Download the game', '<h2>Your adventure starts here</h2><p>Install the game client, then extract the patch into its folder and replace the existing files. Launch the game and sign in with your game account.</p><p>Official file links will appear here when the server is ready to launch.</p>'],
        ] as $locale => [$title, $body]) {
            $translations[$locale] = ['locale' => $locale, 'slug' => self::SLUG, 'title' => $title, 'body' => $body, 'seo_title' => '', 'seo_description' => ''];
        }

        return $translations;
    }
}
