<?php

namespace App\Http\Controllers\Admin;

use App\Auth\AdminRole;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdministratorTwoFactorController extends Controller
{
    public function destroy(Request $request, Admin $administrator, AuditLogger $auditLogger): RedirectResponse
    {
        /** @var Admin $currentAdmin */
        $currentAdmin = Auth::guard('admin')->user();

        if ($currentAdmin->is($administrator)) {
            throw ValidationException::withMessages([
                'current_password' => __('Manage your own two-factor authentication from Account security.'),
            ]);
        }

        abort_unless(
            $currentAdmin->role === AdminRole::Owner
            || ($currentAdmin->role === AdminRole::Administrator && ! $administrator->isOwner()),
            403,
        );

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:4096'],
        ]);

        if (! Hash::check((string) $validated['current_password'], $currentAdmin->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('The current password is incorrect.'),
            ]);
        }

        if (! $administrator->twoFactorEnabled()) {
            return back()->with('status', __('Two-factor authentication is already disabled for this administrator.'));
        }

        $administrator->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'session_version' => $administrator->session_version + 1,
            'remember_token' => Str::random(60),
        ])->save();

        $auditLogger->success(
            category: 'admin',
            action: 'administrator.2fa_reset',
            actor: $currentAdmin,
            target: $administrator,
            details: ['sessions_invalidated' => true],
        );

        return back()->with('status', __('Two-factor authentication was reset for :administrator.', [
            'administrator' => $administrator->email,
        ]));
    }
}
