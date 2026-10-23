<?php

namespace App\Services\Pages;

use App\Models\CmsSetting;
use App\Models\Page;
use Illuminate\Support\Facades\DB;

/**
 * The example "Download the game" page created on installation: an ordinary page with the
 * address "downloads", which the theme's Download client buttons open.
 */
final class DownloadPages
{
    public const KEY = 'content.downloads_initialized';

    public const SLUG = 'downloads';

    public const EDITABLE_KEY = 'content.downloads_editable_blocks_initialized';

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

    /** Move previously rendered cards into content once, retaining the owner's text and links. */
    public function migrateEditableContent(): void
    {
        DB::transaction(function (): void {
            $inserted = CmsSetting::query()->insertOrIgnore([
                'key' => self::EDITABLE_KEY, 'value' => '1', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $page = Page::query()->with('translations')->where('slug', self::SLUG)->first();
            if ($inserted !== 1 || $page === null) {
                return;
            }
            foreach ($page->translations as $translation) {
                if (! str_contains($translation->body, 'data-content-block="cards"')) {
                    $translation->update(['body' => $this->cardsHtml($translation->locale,
                        self::safeUrl($page->download_client_url), self::safeUrl($page->download_patch_url)).$translation->body]);
                }
            }
        });
    }

    private function cardsHtml(string $locale, ?string $client = null, ?string $patch = null): string
    {
        $labels = $locale === 'ru' ? [
            ['Клиент игры', 'Установите клиент игры перед установкой патча сервера.', 'Скачать клиент'],
            ['Патч сервера', 'Распакуйте патч в папку игры с заменой файлов.', 'Скачать патч'],
        ] : [
            ['Game client', 'Install the game client before applying the server patch.', 'Download client'],
            ['Server patch', 'Extract the patch into the game folder and replace existing files.', 'Download patch'],
        ];
        $html = '<div data-content-block="cards">';
        foreach ($labels as $index => [$title, $description, $button]) {
            $url = ($index === 0 ? $client : $patch) ?? '#';
            $html .= '<div data-content-block="card"><h2>'.e($title).'</h2><p>'.e($description).'</p><p><a href="'.e($url).'" data-content-button="primary">'.e($button).'</a></p></div>';
        }

        return $html.'</div>';
    }

    /** @return array<string, array{locale:string,slug:string,title:string,body:string,seo_title:string,seo_description:string}> */
    public function templateTranslations(): array
    {
        $translations = [];
        foreach ([
            'ru' => ['Скачать игру', '<h2>Всё готово для начала приключения</h2><p>Установите клиент игры, затем распакуйте патч в его папку с заменой файлов. После установки запустите игру и войдите в свой игровой аккаунт.</p><p>Официальные ссылки на файлы появятся здесь, когда сервер будет готов к запуску.</p>'],
            'en' => ['Download the game', '<h2>Your adventure starts here</h2><p>Install the game client, then extract the patch into its folder and replace the existing files. Launch the game and sign in with your game account.</p><p>Official file links will appear here when the server is ready to launch.</p>'],
        ] as $locale => [$title, $body]) {
            $translations[$locale] = ['locale' => $locale, 'slug' => self::SLUG, 'title' => $title, 'body' => $this->cardsHtml($locale).$body, 'seo_title' => '', 'seo_description' => ''];
        }

        return $translations;
    }
}
