<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveNewsRequest;
use App\Models\News;
use App\Models\NewsTranslation;
use App\Services\AuditLogger;
use App\Services\Localization\LanguageManager;
use App\Services\News\NewsHtmlSanitizer;
use App\Services\News\NewsImageStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class NewsController extends Controller
{
    public function __construct(
        private readonly NewsHtmlSanitizer $sanitizer,
        private readonly NewsImageStorage $images,
        private readonly AuditLogger $auditLogger,
        private readonly LanguageManager $languages,
    ) {}

    public function index(): View
    {
        return view('admin.news.index', [
            'news' => News::query()->with('translations')->latest('created_at')->paginate(10),
            'totalCount' => News::query()->count(),
            'publishedCount' => News::query()
                ->where('is_published', true)
                ->whereNotNull('published_at')
                ->where('published_at', '<=', now())
                ->count(),
            'scheduledCount' => News::query()
                ->where('is_published', true)
                ->where('published_at', '>', now())
                ->count(),
            'draftCount' => News::query()->where('is_published', false)->count(),
            'languages' => $this->languages->enabled(),
        ]);
    }

    public function create(): View
    {
        $newsItem = new News([
            'is_published' => false,
            'published_at' => now(),
        ]);

        return view('admin.news.create', [
            'newsItem' => $newsItem,
            'translations' => $this->emptyTranslations(),
            'languages' => $this->languages->enabled(),
            'defaultLocale' => $this->languages->default(),
        ]);
    }

    public function store(SaveNewsRequest $request): RedirectResponse
    {
        $payload = $this->preparePayload($request);
        $defaultLocale = $this->languages->default();
        $defaultTranslation = $payload['translations'][$defaultLocale];
        $legacySlug = $this->makeUniqueSlug($defaultTranslation['title']);
        $coverPath = null;

        if ($request->hasFile('cover_image')) {
            $coverPath = $this->images->storeCover($request->file('cover_image'));
        }

        try {
            $newsItem = DB::transaction(function () use ($payload, $defaultTranslation, $legacySlug, $coverPath): News {
                $newsItem = News::query()->create([
                    'title' => $defaultTranslation['title'],
                    'slug' => $legacySlug,
                    'excerpt' => $defaultTranslation['excerpt'],
                    'body' => $defaultTranslation['body'],
                    'image' => $coverPath,
                    'published_at' => $payload['published_at'],
                    'is_published' => $payload['is_published'],
                ]);

                $this->syncTranslations($newsItem, $payload['translations']);

                return $newsItem->load('translations');
            });
        } catch (Throwable $exception) {
            $this->images->deleteCover($coverPath);
            throw $exception;
        }

        Log::notice('CMS news created.', [
            'admin_id' => Auth::guard('admin')->id(),
            'news_id' => $newsItem->id,
            'slug' => $newsItem->slug,
            'locales' => array_keys($payload['translations']),
            'is_published' => $newsItem->is_published,
            'has_cover' => $newsItem->image !== null,
            'ip_address' => $request->ip(),
        ]);

        $this->auditLogger->success(
            category: 'admin',
            action: 'news.created',
            target: $newsItem,
            details: [
                'slug' => $newsItem->slug,
                'locales' => array_keys($payload['translations']),
                'publication_state' => $newsItem->publicationState(),
                'published_at' => $newsItem->published_at?->toDateTimeString(),
                'has_cover' => $newsItem->image !== null,
            ],
        );

        return redirect()
            ->route('admin.news.index')
            ->with('status', __('News “:title” created.', ['title' => $newsItem->titleFor()]));
    }

    public function preview(SaveNewsRequest $request): Response
    {
        $sourceNews = null;
        $newsId = $request->integer('news_id');

        if ($newsId > 0) {
            $sourceNews = News::query()->with('translations')->findOrFail($newsId);
        }

        $payload = $this->preparePayload($request);
        $previewLocale = $this->languages->normalizeCode((string) $request->input('preview_locale'));
        if ($previewLocale === null || ! isset($payload['translations'][$previewLocale])) {
            $previewLocale = $this->languages->default();
        }
        $translation = $payload['translations'][$previewLocale]
            ?? $payload['translations'][$this->languages->default()];

        app()->setLocale($previewLocale);
        $preview = new News([
            'title' => $translation['title'],
            'slug' => 'preview',
            'excerpt' => $translation['excerpt'],
            'body' => $translation['body'],
            'published_at' => $payload['published_at'],
            'is_published' => $payload['is_published'],
        ]);
        $preview->image = null;

        if ($request->hasFile('cover_image')) {
            $preview->setAttribute(
                'preview_cover_url',
                $this->images->previewDataUrl($request->file('cover_image'))
            );
        } elseif (! $request->boolean('remove_cover_image') && $sourceNews !== null) {
            $preview->image = $sourceNews->image;
        }

        Log::info('CMS news preview rendered.', [
            'admin_id' => Auth::guard('admin')->id(),
            'news_id' => $sourceNews?->id,
            'locale' => $previewLocale,
            'ip_address' => $request->ip(),
        ]);

        return response()
            ->view('theme::news.show', [
                'news' => $preview,
                'isPreview' => true,
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function edit(News $news): View
    {
        $news->load('translations');

        return view('admin.news.edit', [
            'newsItem' => $news,
            'translations' => $this->translationValues($news),
            'languages' => $this->languages->enabled(),
            'defaultLocale' => $this->languages->default(),
        ]);
    }

    public function update(SaveNewsRequest $request, News $news): RedirectResponse
    {
        $news->load('translations');
        $beforeAudit = [
            'title' => $news->title,
            'translations' => $news->translations->pluck('title', 'locale')->all(),
            'content_hash' => hash('sha256', implode('|', $news->translations->pluck('body')->all())),
            'published_at' => $news->published_at?->toDateTimeString(),
            'is_published' => (bool) $news->is_published,
            'has_cover' => $news->image !== null,
        ];
        $payload = $this->preparePayload($request);
        $defaultTranslation = $payload['translations'][$this->languages->default()];
        $oldCover = $news->image;
        $oldContentImages = $this->contentImagePaths($news);
        $newCover = null;
        $coverChanged = false;

        if ($request->hasFile('cover_image')) {
            $newCover = $this->images->storeCover($request->file('cover_image'));
            $coverChanged = true;
        } elseif ($request->boolean('remove_cover_image')) {
            $coverChanged = true;
        }

        try {
            DB::transaction(function () use ($news, $payload, $defaultTranslation, $newCover, $coverChanged): void {
                $values = [
                    'title' => $defaultTranslation['title'],
                    'excerpt' => $defaultTranslation['excerpt'],
                    'body' => $defaultTranslation['body'],
                    'published_at' => $payload['published_at'],
                    'is_published' => $payload['is_published'],
                ];

                if ($coverChanged) {
                    $values['image'] = $newCover;
                }

                $news->update($values);
                $this->syncTranslations($news, $payload['translations']);
            });
        } catch (Throwable $exception) {
            $this->images->deleteCover($newCover);
            throw $exception;
        }

        $news->refresh()->load('translations');

        if ($coverChanged && $oldCover !== $news->image) {
            $this->images->deleteIfUnreferenced($oldCover);
        }

        $newContentImages = $this->contentImagePaths($news);
        foreach (array_diff($oldContentImages, $newContentImages) as $removedImage) {
            $this->images->deleteIfUnreferenced($removedImage);
        }

        Log::notice('CMS news updated.', [
            'admin_id' => Auth::guard('admin')->id(),
            'news_id' => $news->id,
            'slug' => $news->slug,
            'locales' => $news->translations->pluck('locale')->all(),
            'is_published' => $news->is_published,
            'has_cover' => $news->image !== null,
            'ip_address' => $request->ip(),
        ]);

        $afterAudit = [
            'title' => $news->title,
            'translations' => $news->translations->pluck('title', 'locale')->all(),
            'content_hash' => hash('sha256', implode('|', $news->translations->pluck('body')->all())),
            'published_at' => $news->published_at?->toDateTimeString(),
            'is_published' => (bool) $news->is_published,
            'has_cover' => $news->image !== null,
        ];
        $changes = $this->auditChanges($beforeAudit, $afterAudit);

        if (isset($changes['content_hash'])) {
            unset($changes['content_hash']);
            $changes['body'] = ['old' => __('Text before change'), 'new' => __('Text changed')];
        }

        $this->auditLogger->success(
            category: 'admin',
            action: 'news.updated',
            target: $news,
            details: ['changes' => $changes],
        );

        return redirect()
            ->route('admin.news.index')
            ->with('status', __('News “:title” saved.', ['title' => $news->titleFor()]));
    }

    public function destroy(News $news): RedirectResponse
    {
        $news->load('translations');
        $title = $news->titleFor();
        $newsId = $news->id;
        $slug = $news->slug;
        $cover = $news->image;
        $contentImages = $this->contentImagePaths($news);

        DB::transaction(function () use ($news): void {
            $news->forceDelete();
        });

        $this->images->deleteIfUnreferenced($cover);
        foreach ($contentImages as $contentImage) {
            $this->images->deleteIfUnreferenced($contentImage);
        }

        Log::warning('CMS news deleted.', [
            'admin_id' => Auth::guard('admin')->id(),
            'news_id' => $newsId,
            'slug' => $slug,
            'ip_address' => request()->ip(),
        ]);

        $this->auditLogger->success(
            category: 'admin',
            action: 'news.deleted',
            target: $title,
            details: [
                'news_id' => $newsId,
                'slug' => $slug,
                'removed_cover' => $cover !== null,
                'removed_content_images' => count($contentImages),
            ],
        );

        return redirect()
            ->route('admin.news.index')
            ->with('status', __('News “:title” deleted.', ['title' => $title]));
    }

    /** @return array{translations:array<string,array{title:string,excerpt:string,body:string}>,published_at:mixed,is_published:bool} */
    private function preparePayload(SaveNewsRequest $request): array
    {
        $validated = $request->validated();
        $translations = [];
        $inputTranslations = $validated['translations'] ?? null;

        if (is_array($inputTranslations)) {
            foreach ($this->languages->enabledCodes() as $locale) {
                $input = is_array($inputTranslations[$locale] ?? null) ? $inputTranslations[$locale] : [];
                $title = trim((string) ($input['title'] ?? ''));
                $excerpt = trim((string) ($input['excerpt'] ?? ''));
                $body = $this->sanitizer->sanitize(trim((string) ($input['body'] ?? '')));

                if ($locale !== $this->languages->default() && $title === '' && $excerpt === '' && $this->sanitizer->plainText($body) === '') {
                    continue;
                }

                $translations[$locale] = $this->normalizeTranslation($locale, $title, $excerpt, $body);
            }
        } else {
            $locale = $this->languages->default();
            $translations[$locale] = $this->normalizeTranslation(
                $locale,
                trim((string) ($validated['title'] ?? '')),
                trim((string) ($validated['excerpt'] ?? '')),
                $this->sanitizer->sanitize(trim((string) ($validated['body'] ?? ''))),
            );
        }

        if (! isset($translations[$this->languages->default()])) {
            throw ValidationException::withMessages([
                'translations.'.$this->languages->default().'.body' => __('Add content in the default language.'),
            ]);
        }

        $publishedAt = $validated['published_at'] ?? null;
        $isPublished = $request->boolean('is_published');
        if ($isPublished && empty($publishedAt)) {
            $publishedAt = now();
        }

        return [
            'translations' => $translations,
            'published_at' => $publishedAt,
            'is_published' => $isPublished,
        ];
    }

    /** @return array{title:string,excerpt:string,body:string} */
    private function normalizeTranslation(string $locale, string $title, string $excerpt, string $body): array
    {
        if ($this->sanitizer->plainText($body) === '') {
            throw ValidationException::withMessages([
                'translations.'.$locale.'.body' => __('Add at least one text paragraph to the news item.'),
            ]);
        }

        if ($excerpt === '') {
            $excerpt = Str::limit(
                preg_replace('/\s+/u', ' ', $this->sanitizer->plainText($body)) ?? '',
                300
            );
        }

        return [
            'title' => $title,
            'excerpt' => $excerpt,
            'body' => $body,
        ];
    }

    /** @param array<string,array{title:string,excerpt:string,body:string}> $translations */
    private function syncTranslations(News $news, array $translations): void
    {
        $existing = $news->translations()->get()->keyBy('locale');

        foreach ($translations as $locale => $values) {
            $current = $existing->get($locale);
            $slug = $current instanceof NewsTranslation
                ? $current->slug
                : $this->makeUniqueTranslationSlug($values['title'], $locale, $news->id);

            $news->translations()->updateOrCreate(
                ['locale' => $locale],
                [
                    'title' => $values['title'],
                    'slug' => $slug,
                    'excerpt' => $values['excerpt'],
                    'body' => $values['body'],
                ],
            );
        }

        $news->translations()
            ->whereIn('locale', $this->languages->enabledCodes())
            ->whereNotIn('locale', array_keys($translations))
            ->delete();
    }

    /** @return array<string,array{title:string,excerpt:string,body:string}> */
    private function emptyTranslations(): array
    {
        $translations = [];
        foreach ($this->languages->enabledCodes() as $locale) {
            $translations[$locale] = ['title' => '', 'excerpt' => '', 'body' => ''];
        }

        return $translations;
    }

    /** @return array<string,array{title:string,excerpt:string,body:string}> */
    private function translationValues(News $news): array
    {
        $values = $this->emptyTranslations();

        foreach ($news->translations as $translation) {
            if (isset($values[$translation->locale])) {
                $values[$translation->locale] = [
                    'title' => (string) $translation->title,
                    'excerpt' => (string) $translation->excerpt,
                    'body' => (string) $translation->body,
                ];
            }
        }

        $default = $this->languages->default();
        if (($values[$default]['title'] ?? '') === '') {
            $values[$default] = [
                'title' => (string) $news->title,
                'excerpt' => (string) $news->excerpt,
                'body' => (string) $news->body,
            ];
        }

        return $values;
    }

    /** @return array<int,string> */
    private function contentImagePaths(News $news): array
    {
        $paths = $this->images->extractContentPaths((string) $news->body);
        foreach ($news->translations as $translation) {
            $paths = array_merge($paths, $this->images->extractContentPaths((string) $translation->body));
        }

        return array_values(array_unique($paths));
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after @return array<string, array{old: mixed, new: mixed}> */
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

    private function makeUniqueSlug(string $title): string
    {
        $baseSlug = Str::slug($title) ?: 'news';
        $slug = $baseSlug;
        $suffix = 2;

        while (News::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        return $slug;
    }

    private function makeUniqueTranslationSlug(string $title, string $locale, ?int $ignoreNewsId = null): string
    {
        $baseSlug = Str::slug($title) ?: 'news';
        $slug = $baseSlug;
        $suffix = 2;

        while (NewsTranslation::query()
            ->where('locale', $locale)
            ->where('slug', $slug)
            ->when($ignoreNewsId !== null, fn ($query) => $query->where('news_id', '!=', $ignoreNewsId))
            ->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        return $slug;
    }
}
