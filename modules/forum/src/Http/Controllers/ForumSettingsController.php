<?php

namespace KaevCMS\Modules\Forum\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use KaevCMS\Modules\Forum\Services\ForumImageHosts;

final class ForumSettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(Request $request): View
    {
        $this->owner($request);
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'section' => ['nullable', 'in:general,community'],
        ]);
        $section = (string) ($validated['section'] ?? 'general');
        $search = trim((string) ($validated['q'] ?? ''));

        $settings = DB::table('module_forum_settings')->where('id', 1)->first();
        abort_if($settings === null, 503);

        // Search only on demand, and never expose the full user directory.
        $candidates = collect();
        if ($search !== '') {
            $candidates = User::query()
                ->where('is_active', true)
                ->where(static function ($query) use ($search): void {
                    $query->where('display_name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                    if (ctype_digit($search)) {
                        $query->orWhere('id', (int) $search);
                    }
                })
                ->orderBy('id')
                ->limit(20)
                ->get(['id', 'display_name', 'email', 'is_active']);
        }

        $moderators = User::query()
            ->join('module_forum_moderators as fm', 'fm.user_id', '=', 'users.id')
            ->orderBy('users.id')
            ->get(['users.id', 'users.display_name', 'users.email', 'users.is_active']);
        $moderatorIds = $moderators->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $imageHosts = app(ForumImageHosts::class)->all();
        $activity = app(\KaevCMS\Modules\Forum\Services\ForumActivity::class)->settings();

        return view('module-forum::admin.settings', compact('settings', 'search', 'candidates', 'moderators', 'moderatorIds', 'imageHosts', 'activity', 'section'));
    }

    public function update(Request $request): RedirectResponse
    {
        $owner = $this->owner($request);
        $data = $request->validate([
            'visibility' => ['required', Rule::in(['all', 'registered'])],
            'links_after_days' => ['required', 'integer', 'min:0', 'max:3650'],
        ]);

        $changed = DB::transaction(static function () use ($data): bool {
            $current = DB::table('module_forum_settings')->where('id', 1)->lockForUpdate()->first();
            abort_if($current === null, 503);

            if ($current->visibility === $data['visibility']
                && (int) $current->links_after_days === (int) $data['links_after_days']) {
                return false;
            }

            DB::table('module_forum_settings')->where('id', 1)->update([
                'visibility' => $data['visibility'],
                'links_after_days' => (int) $data['links_after_days'],
                'updated_at' => now(),
            ]);

            return true;
        });

        if ($changed) {
            $this->audit->success(
                category: 'forum',
                action: 'forum.settings_updated',
                actor: $owner,
                target: 'settings',
                details: [
                    'visibility' => $data['visibility'],
                    'links_after_days' => (int) $data['links_after_days'],
                ],
            );
        }

        return $this->redirect($request, 'settings_saved');
    }

    public function updateCommunity(Request $request, ForumImageHosts $imageHosts): RedirectResponse
    {
        $owner = $this->owner($request);
        $data = $request->validate([
            'activity_enabled' => ['required', 'boolean'],
            'activity_limit' => ['required', 'integer', 'min:1', 'max:'.\KaevCMS\Modules\Forum\Services\ForumActivity::MAX_LIMIT],
            'image_hosts' => ['nullable', 'string', 'max:4000'],
        ]);
        $parsed = ForumImageHosts::parse((string) ($data['image_hosts'] ?? ''));
        if ($parsed['invalid'] !== []) {
            throw ValidationException::withMessages([
                'image_hosts' => __('module-forum::messages.image_hosts_invalid', ['hosts' => implode(', ', array_slice($parsed['invalid'], 0, 5))]),
            ]);
        }
        if (count($parsed['hosts']) > ForumImageHosts::MAX_HOSTS) {
            throw ValidationException::withMessages([
                'image_hosts' => __('module-forum::messages.image_hosts_too_many', ['count' => ForumImageHosts::MAX_HOSTS]),
            ]);
        }

        $values = [
            'activity_enabled' => (bool) $data['activity_enabled'],
            'activity_limit' => (int) $data['activity_limit'],
            'image_hosts' => json_encode($parsed['hosts']),
        ];
        $changed = DB::transaction(static function () use ($values): array {
            $current = DB::table('module_forum_settings')->where('id', 1)->lockForUpdate()->first();
            abort_if($current === null, 503);
            $changed = [];
            if ((bool) $current->activity_enabled !== $values['activity_enabled'] || (int) $current->activity_limit !== $values['activity_limit']) {
                $changed[] = 'activity';
            }
            if ($current->image_hosts !== $values['image_hosts']) {
                $changed[] = 'images';
            }
            if ($changed !== []) {
                DB::table('module_forum_settings')->where('id', 1)->update($values + ['updated_at' => now()]);
            }

            return $changed;
        });
        $imageHosts->forget();

        if (in_array('activity', $changed, true)) {
            $this->audit->success(category: 'forum', action: 'forum.activity_updated', actor: $owner, target: 'settings',
                details: ['activity_enabled' => $values['activity_enabled'], 'activity_limit' => $values['activity_limit']]);
        }
        if (in_array('images', $changed, true)) {
            $this->audit->success(category: 'forum', action: 'forum.image_hosts_updated', actor: $owner, target: 'settings',
                details: ['image_hosts' => $parsed['hosts']]);
        }

        return redirect()->route('admin.module-pages.forum.settings', ['adminPath' => $request->route('adminPath'), 'section' => 'community'])
            ->with('status', __('module-forum::messages.settings_saved'));
    }

    public function addModerator(Request $request): RedirectResponse
    {
        $owner = $this->owner($request);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:80'],
        ]);
        $userId = (int) $data['user_id'];

        $created = DB::transaction(static function () use ($userId, $owner): bool {
            $user = User::query()->whereKey($userId)->lockForUpdate()->first();
            if ($user === null || ! $user->is_active) {
                throw ValidationException::withMessages([
                    'user_id' => __('module-forum::messages.moderator_account_missing'),
                ]);
            }

            return DB::table('module_forum_moderators')->insertOrIgnore([
                'user_id' => $userId,
                'assigned_by_admin_id' => $owner->id,
                'created_at' => now(),
            ]) === 1;
        });

        if ($created) {
            $this->audit->success(
                category: 'forum',
                action: 'forum.moderator_assigned',
                actor: $owner,
                target: 'user #'.$userId,
            );
        }

        return $this->redirect($request, $created ? 'moderator_added' : 'moderator_already_assigned', (string) ($data['q'] ?? ''));
    }

    public function removeModerator(Request $request, int $user): RedirectResponse
    {
        $owner = $this->owner($request);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:80']]);
        $deleted = DB::table('module_forum_moderators')->where('user_id', $user)->delete();
        abort_unless($deleted === 1, 404);

        $this->audit->success(
            category: 'forum',
            action: 'forum.moderator_removed',
            actor: $owner,
            target: 'user #'.$user,
        );

        return $this->redirect($request, 'moderator_removed', (string) ($data['q'] ?? ''));
    }

    private function owner(Request $request): Admin
    {
        $admin = $request->user('admin');
        if (! $admin instanceof Admin || ! $admin->isOwner()) {
            abort(403);
        }

        return $admin;
    }

    private function redirect(Request $request, string $message, string $search = ''): RedirectResponse
    {
        $parameters = ['adminPath' => $request->route('adminPath')];
        if (trim($search) !== '') {
            $parameters['q'] = trim($search);
        }

        return redirect()->route('admin.module-pages.forum.settings', $parameters)
            ->with('status', __('module-forum::messages.'.$message));
    }
}
