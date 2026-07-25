@extends('admin.layouts.panel')
@section('title', __('Create administrator'))
@section('description', __('Create a control panel account and choose its access role.'))
@section('content')
<div class="admin-page-toolbar administrator-page-toolbar"><a wire:navigate class="button button-secondary" href="{{ route('admin.administrators.index') }}">← {{ __('Back to list') }}</a></div>
<form class="administrator-form" method="POST" action="{{ route('admin.administrators.store') }}">
    @csrf
    <section class="form-card administrator-form-card">
        <h2>{{ __('Administrator details') }}</h2>
        <div class="form-group"><label for="name">{{ __('Name') }}</label><input id="name" name="name" type="text" maxlength="100" required autocomplete="name" value="{{ old('name') }}"><small>{{ __('Shown in the control panel and audit log.') }}</small></div>
        <div class="form-group"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="255" required autocomplete="username" value="{{ old('email') }}"><small>{{ __('Email is used to sign in to the control panel.') }}</small></div>
        @php($selectedRole = old('role', $defaultRole->value))
        <div class="form-group administrator-role-field">
            <label for="role">{{ __('Role') }}</label>
            <select id="role" name="role" required data-admin-role-select aria-describedby="role_description">
                @foreach($roles as $role)
                    <option value="{{ $role->value }}" data-description="{{ $role->description() }}" @selected($selectedRole === $role->value)>{{ $role->label() }}</option>
                @endforeach
            </select>
            <small id="role_description" data-admin-role-description>{{ collect($roles)->first(fn($role) => $role->value === $selectedRole)?->description() ?? $defaultRole->description() }}</small>
        </div>
        <div class="form-row form-row-equal">
            <div class="form-group"><label for="password">{{ __('Password') }}</label><input id="password" name="password" type="password" maxlength="4096" required autocomplete="new-password"></div>
            <div class="form-group"><label for="password_confirmation">{{ __('Repeat password') }}</label><input id="password_confirmation" name="password_confirmation" type="password" maxlength="4096" required autocomplete="new-password"></div>
        </div>
        <div class="administrator-password-rules"><strong>{{ __('Password requirements') }}</strong><span>{{ __('At least 12 characters, lowercase and uppercase letters, and at least one digit.') }}</span></div>
    </section>
    <div class="admin-actions-panel settings-actions administrator-form-actions"><button class="button button-primary" type="submit">{{ __('Create administrator') }}</button><a wire:navigate class="button button-secondary" href="{{ route('admin.administrators.index') }}">{{ __('Cancel') }}</a></div>
</form>
@endsection

@push('scripts')
<script src="{{ asset('assets/admin/js/admin-role-select.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
