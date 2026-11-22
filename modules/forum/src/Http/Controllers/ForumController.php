<?php

namespace KaevCMS\Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use KaevCMS\Modules\Forum\Services\ForumActorResolver;
use KaevCMS\Modules\Forum\Services\ForumPermissions;
use KaevCMS\Modules\Forum\Services\ForumStructure;

final class ForumController extends Controller
{
    public function index(
        Request $request,
        ForumActorResolver $actors,
        ForumPermissions $permissions,
        ForumStructure $structure,
    ): Response {
        $actor = $actors->resolve($request);
        $categories = $structure->visibleCategories($actor, $permissions);

        return response()->view('module-forum::index', [
            'actor' => $actor,
            'identities' => $actors->identities($request),
            'categories' => $categories,
            'activity' => $structure->sectionActivity($categories),
            'activityTabs' => app(\KaevCMS\Modules\Forum\Services\ForumActivity::class)->tabs(
                $categories->flatMap(static fn ($category) => $category->sections->pluck('id'))->map(static fn ($id): int => (int) $id)->values()->all(),
                $actor->user,
                app(\KaevCMS\Modules\Forum\Services\ForumActivity::class)->limit(),
            ),
            'activityTab' => in_array($request->query('activity'), ['latest', 'new', 'popular', 'mine'], true)
                ? (string) $request->query('activity') : 'latest',
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function chooseAuthor(Request $request, ForumActorResolver $actors): RedirectResponse
    {
        $validated = $request->validate(['mode' => ['required', 'in:admin,user']]);
        abort_unless($actors->select($request, $validated['mode']), 403);

        return redirect()->route('modules.forum.index');
    }
}
