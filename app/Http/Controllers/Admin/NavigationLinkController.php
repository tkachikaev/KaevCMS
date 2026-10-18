<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveNavigationLinkRequest;
use App\Models\NavigationLink;
use App\Services\AuditLogger;
use App\Services\Localization\LanguageManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class NavigationLinkController extends Controller
{
    public function __construct(
        private readonly LanguageManager $languages,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(): View
    {
        return view('admin.navigation.index', [
            'links' => NavigationLink::query()->with('translations')->ordered()->paginate(15),
        ]);
    }

    public function create(): View
    {
        return $this->editor(new NavigationLink([
            'is_active' => true,
            'show_in_header' => true,
            'show_in_footer' => false,
            'open_new_tab' => false,
            'sort_order' => 100,
        ]));
    }

    public function store(SaveNavigationLinkRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $link = DB::transaction(function () use ($data): NavigationLink {
            $link = NavigationLink::query()->create($this->attributes($data));
            $this->saveTranslations($link, $data);

            return $link;
        });

        $this->auditLogger->success('admin', 'navigation.created', target: $link, details: ['url' => $link->url]);

        return redirect()->route('admin.navigation.index')->with('status', __('Navigation link created.'));
    }

    public function edit(NavigationLink $navigationLink): View
    {
        return $this->editor($navigationLink->load('translations'));
    }

    public function update(SaveNavigationLinkRequest $request, NavigationLink $navigationLink): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($navigationLink, $data): void {
            $navigationLink->update($this->attributes($data));
            $this->saveTranslations($navigationLink, $data);
        });
        $this->auditLogger->success('admin', 'navigation.updated', target: $navigationLink);

        return redirect()->route('admin.navigation.index')->with('status', __('Navigation link saved.'));
    }

    public function destroy(NavigationLink $navigationLink): RedirectResponse
    {
        $name = $navigationLink->load('translations')->titleFor();
        $navigationLink->delete();
        $this->auditLogger->success('admin', 'navigation.deleted', target: $name);

        return redirect()->route('admin.navigation.index')->with('status', __('Navigation link deleted.'));
    }

    private function editor(NavigationLink $link): View
    {
        return view('admin.navigation.form', [
            'link' => $link,
            'languages' => $this->languages->enabled(),
            'defaultLocale' => $this->languages->default(),
            'translations' => $link->exists ? $link->translations->pluck('title', 'locale')->all() : [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'url' => $data['url'],
            'is_active' => $data['is_active'],
            'show_in_header' => $data['show_in_header'],
            'show_in_footer' => $data['show_in_footer'],
            'open_new_tab' => $data['open_new_tab'],
            'sort_order' => $data['sort_order'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function saveTranslations(NavigationLink $link, array $data): void
    {
        foreach ($this->languages->enabledCodes() as $locale) {
            $title = trim((string) ($data['translations'][$locale]['title'] ?? ''));
            if ($title === '') {
                $link->translations()->where('locale', $locale)->delete();
            } else {
                $link->translations()->updateOrCreate(['locale' => $locale], ['title' => $title]);
            }
        }
    }
}
