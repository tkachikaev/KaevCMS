<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SavePageRequest;
use App\Models\Page;
use App\Models\PageTranslation;
use App\Services\AuditLogger;
use App\Services\Localization\LanguageManager;
use App\Services\Pages\PageHtmlSanitizer;
use App\Services\Pages\PageImageStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class PageController extends Controller
{
    public function __construct(
        private readonly PageHtmlSanitizer $sanitizer,
        private readonly PageImageStorage $images,
        private readonly AuditLogger $auditLogger,
        private readonly LanguageManager $languages,
    ) {}

    public function index(): View
    {
        return view('admin.pages.index', [
            'pages' => Page::query()->with('translations')->ordered()->paginate(15),
            'totalCount' => Page::query()->count(),
            'publishedCount' => Page::query()->published()->count(),
            'draftCount' => Page::query()->where('is_published', false)->count(),
            'menuCount' => Page::query()->published()->where(fn ($query) => $query
                ->where('show_in_header', true)
                ->orWhere('show_in_footer', true))->count(),
            'languages' => $this->languages->enabled(),
        ]);
    }

    public function create(): View
    {
        return view('admin.pages.create', [
            'pageItem' => new Page([
                'is_published' => false,
                'show_in_header' => false,
                'show_in_footer' => true,
                'sort_order' => 100,
            ]),
            'translations' => $this->emptyTranslations(),
            'languages' => $this->languages->enabled(),
            'defaultLocale' => $this->languages->default(),
        ]);
    }

    public function store(SavePageRequest $request): RedirectResponse
    {
        $payload = $this->preparePayload($request);
        $default = $payload['translations'][$this->languages->default()];

        $page = DB::transaction(function () use ($payload, $default): Page {
            $page = Page::query()->create([
                'slug' => $this->makeUniqueBaseSlug($default['slug']),
                'is_published' => $payload['is_published'],
                'show_in_header' => $payload['show_in_header'],
                'show_in_footer' => $payload['show_in_footer'],
                'sort_order' => $payload['sort_order'],
            ]);

            $this->syncTranslations($page, $payload['translations']);
            $page->update(['slug' => $this->makeUniqueBaseSlug($page->slugFor($this->languages->default(), false), $page->id)]);

            return $page->load('translations');
        });

        Log::notice('CMS page created.', [
            'admin_id' => Auth::guard('admin')->id(),
            'page_id' => $page->id,
            'slug' => $page->slug,
            'locales' => array_keys($payload['translations']),
            'is_published' => $page->is_published,
            'ip_address' => $request->ip(),
        ]);

        $this->auditLogger->success(
            category: 'admin',
            action: 'page.created',
            target: $page,
            details: [
                'slug' => $page->slug,
                'locales' => array_keys($payload['translations']),
                'is_published' => $page->is_published,
                'show_in_header' => $page->show_in_header,
                'show_in_footer' => $page->show_in_footer,
                'sort_order' => $page->sort_order,
            ],
        );

        return redirect()->route('admin.pages.index')
            ->with('status', __('Page “:title” created.', ['title' => $page->titleFor()]));
    }

    public function preview(SavePageRequest $request): Response
    {
        $payload = $this->preparePayload($request);
        $locale = $this->languages->normalizeCode((string) $request->input('preview_locale'));
        if ($locale === null || ! isset($payload['translations'][$locale])) {
            $locale = $this->languages->default();
        }

        $values = $payload['translations'][$locale] ?? $payload['translations'][$this->languages->default()];
        app()->setLocale($locale);

        $preview = new Page([
            'slug' => $values['slug'],
            'is_published' => $payload['is_published'],
            'show_in_header' => $payload['show_in_header'],
            'show_in_footer' => $payload['show_in_footer'],
            'sort_order' => $payload['sort_order'],
        ]);
        $preview->setRelation('translations', collect([
            new PageTranslation([
                'locale' => $locale,
                'title' => $values['title'],
                'slug' => $values['slug'],
                'body' => $values['body'],
                'seo_title' => $values['seo_title'],
                'seo_description' => $values['seo_description'],
            ]),
        ]));

        return response()->view('theme::pages.show', [
            'page' => $preview,
            'isPreview' => true,
            'previewKind' => 'page',
        ])->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function edit(Page $page): View
    {
        $page->load('translations');

        return view('admin.pages.edit', [
            'pageItem' => $page,
            'translations' => $this->translationValues($page),
            'languages' => $this->languages->enabled(),
            'defaultLocale' => $this->languages->default(),
        ]);
    }

    public function update(SavePageRequest $request, Page $page): RedirectResponse
    {
        $page->load('translations');
        $before = [
            'slug' => $page->slug,
            'titles' => $page->translations->pluck('title', 'locale')->all(),
            'content_hash' => hash('sha256', implode('|', $page->translations->pluck('body')->all())),
            'is_published' => $page->is_published,
            'show_in_header' => $page->show_in_header,
            'show_in_footer' => $page->show_in_footer,
            'sort_order' => $page->sort_order,
        ];
        $oldImages = $this->contentImagePaths($page);
        $payload = $this->preparePayload($request);

        DB::transaction(function () use ($page, $payload): void {
            $this->syncTranslations($page, $payload['translations']);
            $page->update([
                'slug' => $this->makeUniqueBaseSlug($page->slugFor($this->languages->default(), false), $page->id),
                'is_published' => $payload['is_published'],
                'show_in_header' => $payload['show_in_header'],
                'show_in_footer' => $payload['show_in_footer'],
                'sort_order' => $payload['sort_order'],
            ]);
        });

        $page->refresh()->load('translations');
        foreach (array_diff($oldImages, $this->contentImagePaths($page)) as $removedImage) {
            $this->images->deleteIfUnreferenced($removedImage);
        }

        $after = [
            'slug' => $page->slug,
            'titles' => $page->translations->pluck('title', 'locale')->all(),
            'content_hash' => hash('sha256', implode('|', $page->translations->pluck('body')->all())),
            'is_published' => $page->is_published,
            'show_in_header' => $page->show_in_header,
            'show_in_footer' => $page->show_in_footer,
            'sort_order' => $page->sort_order,
        ];
        $changes = $this->auditChanges($before, $after);
        if (isset($changes['content_hash'])) {
            unset($changes['content_hash']);
            $changes['body'] = ['old' => __('Text before change'), 'new' => __('Text changed')];
        }

        $this->auditLogger->success(
            category: 'admin',
            action: 'page.updated',
            target: $page,
            details: ['changes' => $changes],
        );

        return redirect()->route('admin.pages.index')
            ->with('status', __('Page “:title” saved.', ['title' => $page->titleFor()]));
    }

    public function destroy(Page $page): RedirectResponse
    {
        $page->load('translations');
        $title = $page->titleFor();
        $pageId = $page->id;
        $slug = $page->slug;
        $images = $this->contentImagePaths($page);

        DB::transaction(fn () => $page->forceDelete());

        foreach ($images as $image) {
            $this->images->deleteIfUnreferenced($image);
        }

        $this->auditLogger->success(
            category: 'admin',
            action: 'page.deleted',
            target: $title,
            details: [
                'page_id' => $pageId,
                'slug' => $slug,
                'removed_content_images' => count($images),
            ],
        );

        return redirect()->route('admin.pages.index')
            ->with('status', __('Page “:title” deleted.', ['title' => $title]));
    }

    /** @return array{translations:array<string,array{title:string,slug:string,body:string,seo_title:string,seo_description:string}>,is_published:bool,show_in_header:bool,show_in_footer:bool,sort_order:int} */
    private function preparePayload(SavePageRequest $request): array
    {
        $validated = $request->validated();
        $inputTranslations = is_array($validated['translations'] ?? null) ? $validated['translations'] : [];
        $translations = [];

        foreach ($this->languages->enabledCodes() as $locale) {
            $input = is_array($inputTranslations[$locale] ?? null) ? $inputTranslations[$locale] : [];
            $title = trim((string) ($input['title'] ?? ''));
            $requestedSlug = trim((string) ($input['slug'] ?? ''));
            $body = $this->sanitizer->sanitize(trim((string) ($input['body'] ?? '')));
            $seoTitle = trim((string) ($input['seo_title'] ?? ''));
            $seoDescription = trim((string) ($input['seo_description'] ?? ''));

            if ($locale !== $this->languages->default()
                && $title === ''
                && $requestedSlug === ''
                && $this->sanitizer->plainText($body) === ''
                && $seoTitle === ''
                && $seoDescription === '') {
                continue;
            }

            if ($this->sanitizer->plainText($body) === '') {
                throw ValidationException::withMessages([
                    'translations.'.$locale.'.body' => __('Add at least one text paragraph to the page.'),
                ]);
            }

            $slug = Str::slug($requestedSlug !== '' ? $requestedSlug : $title);
            if ($slug === '') {
                throw ValidationException::withMessages([
                    'translations.'.$locale.'.slug' => __('Enter a valid page address.'),
                ]);
            }

            $translations[$locale] = [
                'title' => $title,
                'slug' => $slug,
                'body' => $body,
                'seo_title' => $seoTitle,
                'seo_description' => $seoDescription,
            ];
        }

        if (! isset($translations[$this->languages->default()])) {
            throw ValidationException::withMessages([
                'translations.'.$this->languages->default().'.body' => __('Add content in the default language.'),
            ]);
        }

        return [
            'translations' => $translations,
            'is_published' => $request->boolean('is_published'),
            'show_in_header' => $request->boolean('show_in_header'),
            'show_in_footer' => $request->boolean('show_in_footer'),
            'sort_order' => (int) ($validated['sort_order'] ?? 100),
        ];
    }

    /** @param array<string,array{title:string,slug:string,body:string,seo_title:string,seo_description:string}> $translations */
    private function syncTranslations(Page $page, array $translations): void
    {
        foreach ($translations as $locale => $values) {
            $page->translations()->updateOrCreate(
                ['locale' => $locale],
                [
                    'title' => $values['title'],
                    'slug' => $this->makeUniqueTranslationSlug($values['slug'], $locale, $page->id),
                    'body' => $values['body'],
                    'seo_title' => $values['seo_title'] !== '' ? $values['seo_title'] : null,
                    'seo_description' => $values['seo_description'] !== '' ? $values['seo_description'] : null,
                ],
            );
        }

        $page->translations()
            ->whereIn('locale', $this->languages->enabledCodes())
            ->whereNotIn('locale', array_keys($translations))
            ->delete();

        $page->unsetRelation('translations');
        $page->load('translations');
    }

    /** @return array<string,array{title:string,slug:string,body:string,seo_title:string,seo_description:string}> */
    private function emptyTranslations(): array
    {
        $values = [];
        foreach ($this->languages->enabledCodes() as $locale) {
            $values[$locale] = [
                'title' => '', 'slug' => '', 'body' => '', 'seo_title' => '', 'seo_description' => '',
            ];
        }

        return $values;
    }

    /** @return array<string,array{title:string,slug:string,body:string,seo_title:string,seo_description:string}> */
    private function translationValues(Page $page): array
    {
        $values = $this->emptyTranslations();
        foreach ($page->translations as $translation) {
            if (! isset($values[$translation->locale])) {
                continue;
            }

            $values[$translation->locale] = [
                'title' => (string) $translation->title,
                'slug' => (string) $translation->slug,
                'body' => (string) $translation->body,
                'seo_title' => (string) $translation->seo_title,
                'seo_description' => (string) $translation->seo_description,
            ];
        }

        return $values;
    }

    /** @return array<int,string> */
    private function contentImagePaths(Page $page): array
    {
        $paths = [];
        foreach ($page->translations as $translation) {
            $paths = array_merge($paths, $this->images->extractContentPaths((string) $translation->body));
        }

        return array_values(array_unique($paths));
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after @return array<string,array{old:mixed,new:mixed}> */
    private function auditChanges(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $key => $newValue) {
            $oldValue = $before[$key] ?? null;
            if ($oldValue !== $newValue) {
                $changes[$key] = ['old' => $oldValue, 'new' => $newValue];
            }
        }

        return $changes;
    }

    private function makeUniqueBaseSlug(string $slug, ?int $ignorePageId = null): string
    {
        $candidate = $slug;
        $suffix = 2;

        while (Page::withTrashed()
            ->where('slug', $candidate)
            ->when($ignorePageId !== null, fn ($query) => $query->where('id', '!=', $ignorePageId))
            ->exists()) {
            $candidate = $slug.'-'.$suffix++;
        }

        return $candidate;
    }

    private function makeUniqueTranslationSlug(string $slug, string $locale, ?int $ignorePageId = null): string
    {
        $candidate = $slug;
        $suffix = 2;

        while (PageTranslation::query()
            ->where('locale', $locale)
            ->where('slug', $candidate)
            ->when($ignorePageId !== null, fn ($query) => $query->where('page_id', '!=', $ignorePageId))
            ->exists()) {
            $candidate = $slug.'-'.$suffix++;
        }

        return $candidate;
    }
}
