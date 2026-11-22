<?php

namespace KaevCMS\Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use KaevCMS\Modules\Forum\Models\ForumCategory;
use KaevCMS\Modules\Forum\Models\ForumSection;
use KaevCMS\Modules\Forum\Models\ForumTopic;
use KaevCMS\Modules\Forum\Services\ForumActorResolver;
use KaevCMS\Modules\Forum\Services\ForumPermissions;
use KaevCMS\Modules\Forum\Services\ForumStructure;

final class ForumStructureController extends Controller
{
    public function __construct(
        private readonly ForumActorResolver $actors,
        private readonly ForumStructure $structure,
    ) {}

    public function createCategory(Request $request): RedirectResponse
    {
        $this->owner($request);
        $category = $this->structure->createCategory($this->names($request));
        $this->audit($request, 'forum.category_created', 'category #'.$category->id);

        return $this->back('category_created');
    }

    public function updateCategory(Request $request, ForumCategory $category): RedirectResponse
    {
        $this->owner($request);
        $this->structure->updateCategory($category, $this->names($request));
        $this->audit($request, 'forum.category_updated', 'category #'.$category->id);

        return $this->back('category_updated');
    }

    public function deleteCategory(Request $request, ForumCategory $category): RedirectResponse
    {
        $this->owner($request);
        $request->validate(['confirm' => ['accepted']]);
        $this->structure->deleteCategory($category);
        $this->audit($request, 'forum.category_deleted', 'category #'.$category->id);

        return $this->back('category_deleted');
    }

    public function moveCategory(Request $request, ForumCategory $category): RedirectResponse
    {
        $this->owner($request);
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];
        $this->structure->moveCategory($category, $direction);
        $this->audit($request, 'forum.category_moved', 'category #'.$category->id);

        return $this->back('order_saved');
    }

    public function createSection(Request $request, ForumCategory $category): RedirectResponse
    {
        $this->owner($request);
        $data = $this->sectionValues($request);
        $data['category_id'] = $category->id;
        $section = $this->structure->createSection($category, $data);
        $this->audit($request, 'forum.section_created', 'section #'.$section->id);

        return $this->back('section_created');
    }

    public function updateSection(Request $request, ForumSection $section): RedirectResponse
    {
        $this->owner($request);
        $data = $this->sectionValues($request, true);
        ForumCategory::query()->findOrFail($data['category_id']);
        $this->structure->updateSection($section, $data);
        $this->audit($request, 'forum.section_updated', 'section #'.$section->id);

        return $this->back('section_updated');
    }

    public function deleteSection(Request $request, ForumSection $section): RedirectResponse
    {
        $this->owner($request);
        $request->validate(['confirm' => ['accepted']]);
        $this->structure->deleteSection($section);
        $this->audit($request, 'forum.section_deleted', 'section #'.$section->id);

        return $this->back('section_deleted');
    }

    public function moveSection(Request $request, ForumSection $section): RedirectResponse
    {
        $this->owner($request);
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];
        $this->structure->moveSection($section, $direction);
        $this->audit($request, 'forum.section_moved', 'section #'.$section->id);

        return $this->back('order_saved');
    }

    public function trash(Request $request): Response
    {
        $this->owner($request);

        return response()->view('module-forum::trash', [
            'actor' => $this->actors->resolve($request),
            'identities' => $this->actors->identities($request),
            'categories' => ForumCategory::onlyTrashed()->orderByDesc('deleted_at')->get(),
            'sections' => ForumSection::onlyTrashed()->orderByDesc('deleted_at')->get(),
            'topics' => ForumTopic::onlyTrashed()
                ->with(['section' => static fn ($query) => $query->withTrashed()->with(['category' => static fn ($category) => $category->withTrashed()])])
                ->orderByDesc('deleted_at')->limit(100)->get(),
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function restoreCategory(Request $request, int $category): RedirectResponse
    {
        $this->owner($request);
        ForumCategory::onlyTrashed()->findOrFail($category)->restore();
        $this->audit($request, 'forum.category_restored', 'category #'.$category);

        return $this->back('category_restored');
    }

    public function restoreSection(Request $request, int $section): RedirectResponse
    {
        $this->owner($request);
        $row = ForumSection::onlyTrashed()->findOrFail($section);
        if (! ForumCategory::query()->whereKey($row->category_id)->exists()) {
            throw ValidationException::withMessages(['category' => __('module-forum::messages.restore_category_first')]);
        }
        $row->restore();
        $this->audit($request, 'forum.section_restored', 'section #'.$section);

        return $this->back('section_restored');
    }

    private function owner(Request $request): void
    {
        abort_unless($this->actors->resolve($request)->isOwner(), 403);
    }

    /** @return array<string, mixed> */
    private function names(Request $request): array
    {
        return $request->validate([
            'name_ru' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'description_ru' => ['nullable', 'string', 'max:500'],
            'description_en' => ['nullable', 'string', 'max:500'],
        ]);
    }

    /** @return array<string, mixed> */
    private function sectionValues(Request $request, bool $editing = false): array
    {
        $rules = [
            'name_ru' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'description_ru' => ['nullable', 'string', 'max:500'],
            'description_en' => ['nullable', 'string', 'max:500'],
            'view_rule' => ['required', Rule::in(ForumPermissions::VIEWS)],
            'topic_rule' => ['required', Rule::in(ForumPermissions::WRITES)],
            'reply_rule' => ['required', Rule::in(ForumPermissions::WRITES)],
        ];

        if ($editing) {
            $rules['category_id'] = ['required', 'integer', 'exists:module_forum_categories,id'];
        }

        return $request->validate($rules);
    }

    private function audit(Request $request, string $action, string $target): void
    {
        app(AuditLogger::class)->success(
            category: 'forum',
            action: $action,
            actor: $this->actors->resolve($request)->admin,
            target: $target,
        );
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('modules.forum.index')
            ->with('forum_status', __('module-forum::messages.'.$message));
    }
}
