<?php

namespace KaevCMS\Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use KaevCMS\Modules\Forum\Models\ForumCategory;
use KaevCMS\Modules\Forum\Models\ForumSection;
use KaevCMS\Modules\Forum\Services\ForumActorResolver;
use KaevCMS\Modules\Forum\Services\ForumPermissions;
use KaevCMS\Modules\Forum\Services\ForumStructure;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class ForumStructureController extends Controller
{
    public function __construct(
        private readonly ForumActorResolver $actors,
        private readonly ForumStructure $structure,
    ) {}

    public function section(Request $request, ForumSection $section, ForumPermissions $permissions): Response
    {
        $category = ForumCategory::query()->findOrFail($section->category_id);
        $actor = $this->actors->resolve($request);
        abort_unless($permissions->canView($actor, $section, $this->structure->globalVisibility()), 404);

        return response()->view('module-forum::section', [
            'actor' => $actor,
            'identities' => $this->actors->identities($request),
            'category' => $category,
            'section' => $section,
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function seed(Request $request): RedirectResponse
    {
        $this->owner($request);
        $created = $this->structure->initialStructure();

        return $this->back($created ? 'structure_created' : 'structure_already_exists');
    }

    public function createCategory(Request $request): RedirectResponse
    {
        $this->owner($request);
        $this->structure->createCategory($this->names($request));

        return $this->back('category_created');
    }

    public function updateCategory(Request $request, ForumCategory $category): RedirectResponse
    {
        $this->owner($request);
        $this->structure->updateCategory($category, $this->names($request));

        return $this->back('category_updated');
    }

    public function deleteCategory(Request $request, ForumCategory $category): RedirectResponse
    {
        $this->owner($request);
        $request->validate(['confirm' => ['accepted']]);
        $this->structure->deleteCategory($category);

        return $this->back('category_deleted');
    }

    public function moveCategory(Request $request, ForumCategory $category): RedirectResponse
    {
        $this->owner($request);
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];
        $this->structure->moveCategory($category, $direction);

        return $this->back('order_saved');
    }

    public function createSection(Request $request, ForumCategory $category): RedirectResponse
    {
        $this->owner($request);
        $data = $this->sectionValues($request);
        $data['category_id'] = $category->id;
        $this->structure->createSection($category, $data);

        return $this->back('section_created');
    }

    public function updateSection(Request $request, ForumSection $section): RedirectResponse
    {
        $this->owner($request);
        $data = $this->sectionValues($request, true);
        ForumCategory::query()->findOrFail($data['category_id']);
        $this->structure->updateSection($section, $data);

        return $this->back('section_updated');
    }

    public function deleteSection(Request $request, ForumSection $section): RedirectResponse
    {
        $this->owner($request);
        $request->validate(['confirm' => ['accepted']]);
        $this->structure->deleteSection($section);

        return $this->back('section_deleted');
    }

    public function moveSection(Request $request, ForumSection $section): RedirectResponse
    {
        $this->owner($request);
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];
        $this->structure->moveSection($section, $direction);

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
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function restoreCategory(Request $request, int $category): RedirectResponse
    {
        $this->owner($request);
        ForumCategory::onlyTrashed()->findOrFail($category)->restore();

        return $this->back('category_restored');
    }

    public function restoreSection(Request $request, int $section): RedirectResponse
    {
        $this->owner($request);
        $row = ForumSection::onlyTrashed()->findOrFail($section);
        if (! ForumCategory::query()->whereKey($row->category_id)->exists()) {
            throw new ConflictHttpException(__('module-forum::messages.restore_category_first'));
        }
        $row->restore();

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

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('modules.forum.index')
            ->with('forum_status', __('module-forum::messages.'.$message));
    }
}
