<?php

namespace App\Http\Controllers\Admin;

use App\Auth\AdminRole;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Rules\PasswordWithinHasherLimit;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdministratorController extends Controller
{
    public function index(): View
    {
        $administrators = Admin::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(25);

        return view('admin.administrators.index', [
            'administrators' => $administrators,
            'totalCount' => Admin::query()->count(),
            'activeCount' => Admin::query()->where('is_active', true)->count(),
            'activeOwnerCount' => $this->activeOwnerCount(),
            'currentAdmin' => $this->currentAdmin(),
        ]);
    }

    public function create(): View
    {
        $currentAdmin = $this->currentAdmin();

        return view('admin.administrators.create', [
            'roles' => AdminRole::assignableBy($currentAdmin->role),
            'defaultRole' => AdminRole::Administrator,
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $currentAdmin = $this->currentAdmin();

        if (! $request->filled('role')) {
            $request->merge(['role' => AdminRole::Administrator->value]);
        }
        $validated = $this->validateProfile(
            $request,
            passwordRequired: true,
            allowedRoles: AdminRole::assignableBy($currentAdmin->role),
        );
        $role = AdminRole::from($validated['role']);

        $administrator = Admin::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $role,
            'is_active' => true,
            'locale' => app()->getLocale(),
        ]);

        $auditLogger->success(
            category: 'admin',
            action: 'administrator.created',
            target: $administrator,
            details: [
                'name' => $administrator->name,
                'email' => $administrator->email,
                'role' => $administrator->role->value,
                'is_active' => true,
            ],
        );

        return redirect()
            ->route('admin.administrators.index')
            ->with('status', __('Administrator created.'));
    }

    public function edit(Admin $administrator): View
    {
        $currentAdmin = $this->currentAdmin();
        $this->assertCanManageTarget($currentAdmin, $administrator);
        $canManageRole = $this->canManageRole($currentAdmin, $administrator);

        return view('admin.administrators.edit', [
            'administrator' => $administrator,
            'currentAdmin' => $currentAdmin,
            'activeCount' => Admin::query()->where('is_active', true)->count(),
            'activeOwnerCount' => $this->activeOwnerCount(),
            'isCurrentAdmin' => $currentAdmin->is($administrator),
            'canManageRole' => $canManageRole,
            'roles' => $canManageRole ? AdminRole::assignableBy($currentAdmin->role) : [],
            'canManageTarget' => $currentAdmin->is($administrator) || $this->canManageOtherTarget($currentAdmin, $administrator),
        ]);
    }

    public function update(Request $request, Admin $administrator, AuditLogger $auditLogger): RedirectResponse
    {
        $currentAdmin = $this->currentAdmin();
        $this->assertCanManageTarget($currentAdmin, $administrator);
        $canManageRole = $this->canManageRole($currentAdmin, $administrator);

        if ($canManageRole && ! $request->filled('role')) {
            $request->merge(['role' => $administrator->role->value]);
        }

        if (! $canManageRole && $request->filled('role') && $request->string('role')->toString() !== $administrator->role->value) {
            abort(403);
        }

        $validated = $this->validateProfile(
            $request,
            $administrator,
            allowedRoles: $canManageRole ? AdminRole::assignableBy($currentAdmin->role) : [],
            roleRequired: $canManageRole,
        );
        $newRole = $canManageRole ? AdminRole::from($validated['role']) : $administrator->role;
        $before = [
            'name' => $administrator->name,
            'email' => $administrator->email,
            'role' => $administrator->role->value,
        ];

        DB::transaction(function () use ($administrator, $validated, $newRole): void {
            /** @var Admin $lockedAdministrator */
            $lockedAdministrator = Admin::query()
                ->whereKey($administrator->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedAdministrator->is_active
                && $lockedAdministrator->isOwner()
                && $newRole !== AdminRole::Owner
                && $this->activeOwnerCount(lock: true) <= 1
            ) {
                throw ValidationException::withMessages([
                    'role' => __('The last active owner cannot be assigned another role.'),
                ]);
            }

            $roleChanged = $lockedAdministrator->role !== $newRole;
            $lockedAdministrator->forceFill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'role' => $newRole,
                'session_version' => $roleChanged
                    ? $lockedAdministrator->session_version + 1
                    : $lockedAdministrator->session_version,
                'remember_token' => $roleChanged
                    ? Str::random(60)
                    : $lockedAdministrator->remember_token,
            ]);

            if ($lockedAdministrator->isDirty()) {
                $lockedAdministrator->save();
            }
        });

        $administrator->refresh();
        $changes = $this->changedValues($before, $administrator, ['name', 'email', 'role']);

        if ($changes === []) {
            return redirect()
                ->route('admin.administrators.edit', $administrator)
                ->with('status', __('No changes.'));
        }

        $auditLogger->success(
            category: 'admin',
            action: array_key_exists('role', $changes) ? 'administrator.role_changed' : 'administrator.updated',
            target: $administrator,
            details: [
                'changes' => $changes,
                'sessions_invalidated' => array_key_exists('role', $changes),
            ],
        );

        return redirect()
            ->route('admin.administrators.edit', $administrator)
            ->with('status', __('Administrator details saved.'));
    }

    public function updatePassword(Request $request, Admin $administrator, AuditLogger $auditLogger): RedirectResponse
    {
        $currentAdmin = $this->currentAdmin();
        $this->assertCanManageTarget($currentAdmin, $administrator);
        $isCurrentAdmin = $currentAdmin->is($administrator);

        $validator = Validator::make(
            [
                'current_password' => (string) $request->input('current_password', ''),
                'password' => (string) $request->input('password', ''),
                'password_confirmation' => (string) $request->input('password_confirmation', ''),
            ],
            [
                'current_password' => $isCurrentAdmin
                    ? ['required', 'string', 'max:4096']
                    : ['nullable', 'string', 'max:4096'],
                'password' => [
                    'required',
                    'string',
                    'confirmed',
                    Password::min(12)->letters()->mixedCase()->numbers(),
                    new PasswordWithinHasherLimit,
                    'max:4096',
                ],
            ],
            [],
            [
                'current_password' => __('Current password validation attribute'),
                'password' => __('New password validation attribute'),
                'password_confirmation' => __('new password confirmation'),
            ],
        );

        $validated = $validator->validate();

        if ($isCurrentAdmin && ! Hash::check($validated['current_password'], $currentAdmin->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('The current password is incorrect.'),
            ]);
        }

        $administrator->forceFill([
            'password' => Hash::make($validated['password']),
            'remember_token' => Str::random(60),
            'session_version' => $administrator->session_version + 1,
        ])->save();

        if ($isCurrentAdmin) {
            $request->session()->regenerate();
            $request->session()->put('admin_session_version', $administrator->session_version);
        }

        $auditLogger->success(
            category: 'admin',
            action: 'administrator.password_changed',
            target: $administrator,
            details: ['password_changed' => true],
        );

        return redirect()
            ->route('admin.administrators.edit', $administrator)
            ->with('status', __('The administrator password was changed.'));
    }

    public function updateStatus(Request $request, Admin $administrator, AuditLogger $auditLogger): RedirectResponse
    {
        $currentAdmin = $this->currentAdmin();
        $this->assertCanManageOtherTarget($currentAdmin, $administrator);

        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);
        $newStatus = (bool) $validated['is_active'];

        if (! $newStatus && $currentAdmin->is($administrator)) {
            throw ValidationException::withMessages([
                'is_active' => __('You cannot disable your own account.'),
            ]);
        }

        if ($administrator->is_active === $newStatus) {
            return back()->with('status', __('The administrator status did not change.'));
        }

        DB::transaction(function () use ($administrator, $newStatus): void {
            /** @var Admin $lockedAdministrator */
            $lockedAdministrator = Admin::query()
                ->whereKey($administrator->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $newStatus) {
                if ($lockedAdministrator->isOwner() && $this->activeOwnerCount(lock: true) <= 1) {
                    throw ValidationException::withMessages([
                        'is_active' => __('The last active owner cannot be disabled.'),
                    ]);
                }

                $lockedActiveAdministrators = Admin::query()
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->get(['id']);
                $activeAdministratorCount = count($lockedActiveAdministrators);

                if ($activeAdministratorCount <= 1) {
                    throw ValidationException::withMessages([
                        'is_active' => __('The last active administrator account cannot be disabled.'),
                    ]);
                }
            }

            $lockedAdministrator->forceFill([
                'is_active' => $newStatus,
                'session_version' => $newStatus
                    ? $lockedAdministrator->session_version
                    : $lockedAdministrator->session_version + 1,
                'remember_token' => $newStatus
                    ? $lockedAdministrator->remember_token
                    : Str::random(60),
            ])->save();
        });

        $administrator->refresh();

        $auditLogger->success(
            category: 'admin',
            action: $newStatus ? 'administrator.activated' : 'administrator.deactivated',
            target: $administrator,
            details: [
                'old' => ! $newStatus,
                'new' => $newStatus,
                'role' => $administrator->role->value,
            ],
        );

        return back()->with(
            'status',
            $newStatus ? __('The administrator account was enabled.') : __('The administrator account was disabled.'),
        );
    }

    /**
     * @param  list<AdminRole>  $allowedRoles
     * @return array{name: string, email: string, password?: string, role?: string}
     */
    private function validateProfile(
        Request $request,
        ?Admin $administrator = null,
        bool $passwordRequired = false,
        array $allowedRoles = [],
        bool $roleRequired = true,
    ): array {
        $data = [
            'name' => trim((string) $request->input('name', '')),
            'email' => Str::lower(trim((string) $request->input('email', ''))),
            'password' => (string) $request->input('password', ''),
            'password_confirmation' => (string) $request->input('password_confirmation', ''),
            'role' => (string) $request->input('role', ''),
        ];

        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('admins', 'email')->ignore($administrator?->getKey()),
            ],
        ];

        if ($passwordRequired) {
            $rules['password'] = [
                'required',
                'string',
                'confirmed',
                Password::min(12)->letters()->mixedCase()->numbers(),
                new PasswordWithinHasherLimit,
                'max:4096',
            ];
        }

        if ($roleRequired) {
            $rules['role'] = [
                'required',
                Rule::in(array_map(static fn (AdminRole $role): string => $role->value, $allowedRoles)),
            ];
        }

        return Validator::make(
            $data,
            $rules,
            [],
            [
                'name' => __('administrator name'),
                'email' => 'email',
                'password' => __('Password validation attribute'),
                'password_confirmation' => __('password confirmation'),
                'role' => __('Role'),
            ],
        )->validate();
    }

    private function currentAdmin(): Admin
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }

    private function assertCanManageTarget(Admin $actor, Admin $target): void
    {
        abort_unless($actor->is($target) || $this->canManageOtherTarget($actor, $target), 403);
    }

    private function assertCanManageOtherTarget(Admin $actor, Admin $target): void
    {
        abort_unless($this->canManageOtherTarget($actor, $target), 403);
    }

    private function canManageOtherTarget(Admin $actor, Admin $target): bool
    {
        if ($actor->role === AdminRole::Owner) {
            return true;
        }

        return $actor->role === AdminRole::Administrator && ! $target->isOwner();
    }

    private function canManageRole(Admin $actor, Admin $target): bool
    {
        return ! $actor->is($target) && $this->canManageOtherTarget($actor, $target);
    }

    private function activeOwnerCount(bool $lock = false): int
    {
        $query = Admin::query()
            ->where('is_active', true)
            ->where('role', AdminRole::Owner->value);

        if ($lock) {
            $lockedOwners = $query->lockForUpdate()->get(['id']);

            return count($lockedOwners);
        }

        return $query->count();
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<int, string>  $fields
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function changedValues(array $before, Admin $administrator, array $fields): array
    {
        $changes = [];

        foreach ($fields as $field) {
            $old = $before[$field] ?? null;
            $value = $administrator->getAttribute($field);
            $new = $value instanceof AdminRole ? $value->value : $value;

            if ($old !== $new) {
                $changes[$field] = ['old' => $old, 'new' => $new];
            }
        }

        return $changes;
    }
}
