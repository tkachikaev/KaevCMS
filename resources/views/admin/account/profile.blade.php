@extends('admin.layouts.panel')
@section('title', __('My profile'))
@section('description', __('My profile'))
@section('content')
@php($isReadOnly = auth('admin')->user()->isReadOnly())
<div class="admin-page-toolbar administrator-page-toolbar">
    <a wire:navigate class="button button-secondary" href="{{ route('admin.dashboard') }}">← {{ __('Back to dashboard') }}</a>
    <div class="administrator-page-statuses">
        <span class="administrator-role-badge role-{{ $administrator->role->value }}">{{ $administrator->roleLabel() }}</span>
    </div>
</div>
<div class="administrator-edit-grid">
    @if ($isReadOnly)
        <section class="form-card administrator-form-card">
            <h2>{{ __('General information') }}</h2>
            <div class="administrator-role-summary"><span>{{ __('Name') }}</span><strong>{{ $administrator->name }}</strong></div>
            <div class="administrator-role-summary"><span>Email</span><strong>{{ $administrator->email }}</strong></div>
            <div class="administrator-role-summary"><span>{{ __('Role') }}</span><strong>{{ $administrator->roleLabel() }}</strong><p>{{ $administrator->roleDescription() }}</p></div>
            <div class="administrator-metadata"><div><span>{{ __('Created') }}</span><strong>{{ $administrator->created_at?->format('d.m.Y H:i') ?? '—' }}</strong></div><div><span>{{ __('Last sign in') }}</span><strong>{{ $administrator->last_login_at?->format('d.m.Y H:i') ?? __('Never') }}</strong></div></div>
        </section>
    @else
        <form class="administrator-form" method="POST" action="{{ route('admin.account.profile.update') }}">
            @csrf
            @method('PUT')
            <section class="form-card administrator-form-card">
                <h2>{{ __('General information') }}</h2>
                <div class="form-group"><label for="name">{{ __('Name') }}</label><input id="name" name="name" type="text" maxlength="100" required autocomplete="name" value="{{ old('name', $administrator->name) }}"><small>{{ __('Shown in the control panel and audit log.') }}</small></div>
                <div class="form-group"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="255" required autocomplete="username" value="{{ old('email', $administrator->email) }}"><small>{{ __('Email is used to sign in to the control panel.') }}</small></div>
                <div class="administrator-role-summary"><span>{{ __('Role') }}</span><strong>{{ $administrator->roleLabel() }}</strong><p>{{ $administrator->roleDescription() }}</p></div>
                <div class="administrator-metadata"><div><span>{{ __('Created') }}</span><strong>{{ $administrator->created_at?->format('d.m.Y H:i') ?? '—' }}</strong></div><div><span>{{ __('Last sign in') }}</span><strong>{{ $administrator->last_login_at?->format('d.m.Y H:i') ?? __('Never') }}</strong></div></div>
            </section>
            <div class="admin-actions-panel settings-actions administrator-form-actions"><button class="button button-primary" type="submit">{{ __('Save details') }}</button></div>
        </form>
    @endif
    <div class="administrator-side-column">
        @unless ($isReadOnly)
            <form class="administrator-form" method="POST" action="{{ route('admin.account.profile.password') }}">
                @csrf
                @method('PUT')
                <section class="form-card administrator-form-card">
                    <h2>{{ __('Change password') }}</h2>
                    <div class="form-group"><label for="current_password">{{ __('Current password') }}</label><input id="current_password" name="current_password" type="password" maxlength="4096" required autocomplete="current-password"><small>{{ __('Your current password is required when changing your own account.') }}</small></div>
                    <div class="form-group"><label for="password">{{ __('New password') }}</label><input id="password" name="password" type="password" maxlength="4096" required autocomplete="new-password"></div>
                    <div class="form-group"><label for="password_confirmation">{{ __('Repeat new password') }}</label><input id="password_confirmation" name="password_confirmation" type="password" maxlength="4096" required autocomplete="new-password"></div>
                    <div class="administrator-password-rules"><strong>{{ __('Requirements') }}</strong><span>{{ __('At least 12 characters, lowercase and uppercase letters, and at least one digit.') }}</span></div>
                    <button class="button button-primary administrator-password-button" type="submit">{{ __('Change password') }}</button>
                </section>
            </form>
        @endunless
        <section class="form-card administrator-form-card administrator-status-card">
            <div class="administrator-security-heading">
                <h2>{{ __('Two-factor authentication') }}</h2>
                <span @class(['status-badge','status-badge-success' => $administrator->twoFactorEnabled(),'status-badge-muted' => ! $administrator->twoFactorEnabled()])>{{ $administrator->twoFactorEnabled() ? __('Two-factor status enabled') : __('Two-factor status disabled') }}</span>
            </div>
            @if ($administrator->twoFactorEnabled())
                <p>{{ __('Connected: :date', ['date' => $administrator->two_factor_confirmed_at?->format('d.m.Y H:i') ?? '—']) }}</p>
                <a wire:navigate class="button button-secondary" href="{{ route('admin.account.security') }}">{{ __('Manage account security') }}</a>
            @else
                <p>{{ __('This administrator has not enabled two-factor authentication.') }}</p>
                @unless ($isReadOnly)
                    <a wire:navigate class="button button-primary" href="{{ route('admin.account.security') }}">{{ __('Enable 2FA') }}</a>
                @endunless
            @endif
        </section>
    </div>
</div>
@endsection
