<?php

namespace KaevCMS\Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use KaevCMS\Modules\Forum\Services\ForumActorResolver;

final class ForumController extends Controller
{
    public function index(Request $request, ForumActorResolver $actors): Response
    {
        return response()->view('module-forum::index', [
            'actor' => $actors->resolve($request),
            'identities' => $actors->identities($request),
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function chooseAuthor(Request $request, ForumActorResolver $actors): RedirectResponse
    {
        $validated = $request->validate(['mode' => ['required', 'in:admin,user']]);
        abort_unless($actors->select($request, $validated['mode']), 403);

        return redirect()->route('modules.forum.index');
    }
}
