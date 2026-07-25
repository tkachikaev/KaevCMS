@extends('admin.layouts.panel')
@section('title', __('Administrator'))
@section('description', __('Edit account details, role, password and status.'))
@section('content')
<div class="admin-page-toolbar administrator-page-toolbar">
    @if(auth('admin')->user()->hasPermission(\App\Auth\AdminPermission::AdministratorsManage))
        <a wire:navigate class="button button-secondary" href="{{ route('admin.administrators.index') }}">← {{ __('Back to list') }}</a>
    @else
        <a wire:navigate class="button button-secondary" href="{{ route('admin.dashboard') }}">← {{ __('Back to dashboard') }}</a>
    @endif
    <div class="administrator-page-statuses">
        <span class="administrator-role-badge role-{{ $administrator->role->value }}">{{ $administrator->roleLabel() }}</span>
        <span @class(['status-badge','status-badge-success' => $administrator->is_active,'status-badge-muted' => ! $administrator->is_active])>{{ $administrator->is_active ? __('Active') : __('Disabled') }}</span>
    </div>
</div>
<div class="administrator-edit-grid">
    <form class="administrator-form" method="POST" action="{{ route('admin.administrators.update', $administrator) }}">
        @csrf
        @method('PUT')
        <section class="form-card administrator-form-card">
            <h2>{{ __('General information') }}</h2>
            <div class="form-group"><label for="name">{{ __('Name') }}</label><input id="name" name="name" type="text" maxlength="100" required autocomplete="name" value="{{ old('name', $administrator->name) }}"><small>{{ __('Shown in the control panel and audit log.') }}</small></div>
            <div class="form-group"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="255" required autocomplete="username" value="{{ old('email', $administrator->email) }}"><small>{{ __('Email is used to sign in to the control panel.') }}</small></div>
            @if($canManageRole)
                @php($selectedRole = old('role', $administrator->role->value))
                <div class="form-group administrator-role-field">
                    <label for="role">{{ __('Role') }}</label>
                    <select id="role" name="role" required data-admin-role-select aria-describedby="role_description">
                        @foreach($roles as $role)
                            <option value="{{ $role->value }}" data-description="{{ $role->description() }}" @selected($selectedRole === $role->value)>{{ $role->label() }}</option>
                        @endforeach
                    </select>
                    <small id="role_description" data-admin-role-description>{{ collect($roles)->first(fn($role) => $role->value === $selectedRole)?->description() ?? $administrator->roleDescription() }}</small>
                </div>
            @else
                <div class="administrator-role-summary"><span>{{ __('Role') }}</span><strong>{{ $administrator->roleLabel() }}</strong><p>{{ $administrator->roleDescription() }}</p></div>
            @endif
            <div class="administrator-metadata"><div><span>{{ __('Created') }}</span><strong>{{ $administrator->created_at?->format('d.m.Y H:i') ?? '—' }}</strong></div><div><span>{{ __('Last sign in') }}</span><strong>{{ $administrator->last_login_at?->format('d.m.Y H:i') ?? __('Never') }}</strong></div></div>
        </section>
        <div class="admin-actions-panel settings-actions administrator-form-actions"><button class="button button-primary" type="submit">{{ __('Save details') }}</button></div>
    </form>
    <div class="administrator-side-column">
        <form class="administrator-form" method="POST" action="{{ route('admin.administrators.password', $administrator) }}">
            @csrf
            @method('PUT')
            <section class="form-card administrator-form-card">
                <h2>{{ __('Change password') }}</h2>
                @if ($isCurrentAdmin)
                    <div class="form-group"><label for="current_password">{{ __('Current password') }}</label><input id="current_password" name="current_password" type="password" maxlength="4096" required autocomplete="current-password"><small>{{ __('Your current password is required when changing your own account.') }}</small></div>
                @endif
                <div class="form-group"><label for="password">{{ __('New password') }}</label><input id="password" name="password" type="password" maxlength="4096" required autocomplete="new-password"></div>
                <div class="form-group"><label for="password_confirmation">{{ __('Repeat new password') }}</label><input id="password_confirmation" name="password_confirmation" type="password" maxlength="4096" required autocomplete="new-password"></div>
                <div class="administrator-password-rules"><strong>{{ __('Requirements') }}</strong><span>{{ __('At least 12 characters, lowercase and uppercase letters, and at least one digit.') }}</span></div>
                <button class="button button-primary administrator-password-button" type="submit">{{ __('Change password') }}</button>
            </section>
        </form>
        <section class="form-card administrator-form-card administrator-status-card">
            <div class="administrator-security-heading">
                <h2>{{ __('Two-factor authentication') }}</h2>
                <span @class(['status-badge','status-badge-success' => $administrator->twoFactorEnabled(),'status-badge-muted' => ! $administrator->twoFactorEnabled()])>{{ $administrator->twoFactorEnabled() ? __('Two-factor status enabled') : __('Two-factor status disabled') }}</span>
            </div>
            @if ($administrator->twoFactorEnabled())
                <p>{{ __('Connected: :date', ['date' => $administrator->two_factor_confirmed_at?->format('d.m.Y H:i') ?? '—']) }}</p>
                @if ($isCurrentAdmin)
                    <a wire:navigate class="button button-secondary" href="{{ route('admin.account.security') }}">{{ __('Manage account security') }}</a>
                @else
                    <p>{{ __('Resetting 2FA removes the secret and recovery codes and revokes active sessions.') }}</p>
                    <form method="POST" action="{{ route('admin.administrators.two-factor.destroy', $administrator) }}">
                        @csrf
                        @method('DELETE')
                        <div class="form-group"><label for="two_factor_current_password">{{ __('Your current password') }}</label><input id="two_factor_current_password" name="current_password" type="password" maxlength="4096" autocomplete="current-password" required></div>
                        <button class="button button-danger administrator-password-button" type="submit">{{ __('Reset 2FA') }}</button>
                    </form>
                @endif
            @else
                <p>{{ __('This administrator has not enabled two-factor authentication.') }}</p>
                @if ($isCurrentAdmin)
                    <a wire:navigate class="button button-primary" href="{{ route('admin.account.security') }}">{{ __('Enable 2FA') }}</a>
                @endif
            @endif
        </section>
        <section class="form-card administrator-form-card administrator-status-card">
            <h2>{{ __('Account status') }}</h2>
            @if ($isCurrentAdmin)
                <p>{{ __('You cannot disable your own account.') }}</p><button class="button button-danger" type="button" disabled>{{ __('Disable') }}</button>
            @elseif(!$canManageTarget)
                <p>{{ __('Your role cannot change the status of this account.') }}</p><button class="button button-danger" type="button" disabled>{{ __('Disable') }}</button>
            @elseif ($administrator->is_active)
                @if ($administrator->isOwner() && $activeOwnerCount <= 1)
                    <p>{{ __('The last active owner cannot be disabled.') }}</p><button class="button button-danger" type="button" disabled>{{ __('Disable') }}</button>
                @elseif ($activeCount <= 1)
                    <p>{{ __('The last active administrator cannot be disabled.') }}</p><button class="button button-danger" type="button" disabled>{{ __('Disable') }}</button>
                @else
                    <p>{{ __('After disabling, the administrator will no longer be able to open the control panel.') }}</p>
                    <form method="POST" action="{{ route('admin.administrators.status', $administrator) }}">@csrf
                    @method('PATCH')<input type="hidden" name="is_active" value="0"><button class="button button-danger" type="submit">{{ __('Disable administrator') }}</button></form>
                @endif
            @else
                <p>{{ __('After enabling, the administrator can sign in with the current email and password.') }}</p>
                <form method="POST" action="{{ route('admin.administrators.status', $administrator) }}">@csrf
                    @method('PATCH')<input type="hidden" name="is_active" value="1"><button class="button button-primary" type="submit">{{ __('Enable administrator') }}</button></form>
            @endif
        </section>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/admin/js/admin-role-select.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
