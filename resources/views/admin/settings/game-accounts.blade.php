@extends('admin.layouts.panel')
@section('title', __('Game accounts'))
@section('description', __('Creation limits and credential policies for the player account.'))
@section('content')
@include('admin.settings._system_tabs')

<form class="settings-form" data-testid="game-account-settings" method="POST" action="{{ route('admin.settings.game-accounts.update') }}">
    @csrf
    @method('PUT')

    <section class="form-card settings-narrow-card">
        <div class="settings-card-heading">
            <div>
                <h2>{{ __('Game account creation') }}</h2>
                <p>{{ __('These rules apply to accounts created by players from the separate player account interface.') }}</p>
            </div>
        </div>

        <x-admin.toggle
            id="creation_enabled"
            name="creation_enabled"
            :label="__('Allow players to create game accounts')"
            :hint="__('Existing linked accounts remain visible when creation is disabled.')"
            :checked="(bool) old('creation_enabled', $settings['enabled'])"
        />

        <x-admin.field
            for="max_accounts"
            name="max_accounts"
            :label="__('Maximum accounts per CMS user')"
        >
            <input id="max_accounts" type="number" name="max_accounts" min="1" max="50" value="{{ old('max_accounts', $settings['max_accounts']) }}" required @if($errors->has('max_accounts')) aria-invalid="true" @endif>
            <x-slot:help>
                <span data-game-account-limit-help>
                    {{ __('The limit is counted across all configured LoginServers.') }}<br>
                    {{ __('Temporarily unavailable game accounts also count toward the limit.') }}
                </span>
            </x-slot:help>
        </x-admin.field>
    </section>

    <section class="form-card settings-narrow-card">
        <div class="settings-card-heading">
            <div>
                <h2>{{ __('Game login policy') }}</h2>
                <p>{{ __('Game logins always allow only Latin letters and digits.') }}</p>
            </div>
        </div>

        <div class="settings-grid two-columns">
            <x-admin.field for="login_min" name="login_min" :label="__('Minimum length')">
                <input id="login_min" type="number" name="login_min" min="1" max="45" value="{{ old('login_min', $settings['login_min']) }}" required @if($errors->has('login_min')) aria-invalid="true" @endif>
            </x-admin.field>
            <x-admin.field for="login_max" name="login_max" :label="__('Maximum length')">
                <input id="login_max" type="number" name="login_max" min="1" max="45" value="{{ old('login_max', $settings['login_max']) }}" required @if($errors->has('login_max')) aria-invalid="true" @endif>
            </x-admin.field>
        </div>

        <x-admin.toggle
            id="login_digit"
            name="login_digit"
            :label="__('Require a digit')"
            :checked="(bool) old('login_digit', $settings['login_digit'])"
        />
    </section>

    <section class="form-card settings-narrow-card">
        <div class="settings-card-heading">
            <div>
                <h2>{{ __('Game password policy') }}</h2>
                <p>{{ __('The policy is used both during account creation and password changes.') }}</p>
            </div>
        </div>

        <div class="settings-grid two-columns">
            <x-admin.field for="password_min" name="password_min" :label="__('Minimum length')">
                <input id="password_min" type="number" name="password_min" min="1" max="45" value="{{ old('password_min', $settings['password_min']) }}" required @if($errors->has('password_min')) aria-invalid="true" @endif>
            </x-admin.field>
            <x-admin.field for="password_max" name="password_max" :label="__('Maximum length')">
                <input id="password_max" type="number" name="password_max" min="1" max="45" value="{{ old('password_max', $settings['password_max']) }}" required @if($errors->has('password_max')) aria-invalid="true" @endif>
            </x-admin.field>
        </div>

        @foreach([
            'password_lower' => __('Require a lowercase letter'),
            'password_upper' => __('Require an uppercase letter'),
            'password_digit' => __('Require a digit'),
        ] as $field => $label)
            <x-admin.toggle
                :id="$field"
                :name="$field"
                :label="$label"
                :checked="(bool) old($field, $settings[$field])"
            />
        @endforeach
    </section>

    <div class="admin-actions-panel settings-actions settings-actions-narrow">
        <button class="button button-primary" type="submit">{{ __('Save settings') }}</button>
    </div>
</form>
@endsection
