@extends('admin.layouts.panel')
@section('title', __('Game accounts'))
@section('description', __('Creation limits and credential policies for the player account.'))
@section('content')
@include('admin.settings._system_tabs')

<form class="settings-form" method="POST" action="{{ route('admin.settings.game-accounts.update') }}">
    @csrf
    @method('PUT')
    <section class="form-card settings-narrow-card">
        <div class="settings-card-heading"><div><h2>{{ __('Game account creation') }}</h2><p>{{ __('These rules apply to accounts created by players from the separate player account interface.') }}</p></div></div>
        <label class="settings-toggle-row" for="creation_enabled"><span><strong>{{ __('Allow players to create game accounts') }}</strong><small>{{ __('Existing linked accounts remain visible when creation is disabled.') }}</small></span><span class="switch-control"><input name="creation_enabled" type="hidden" value="0"><input id="creation_enabled" name="creation_enabled" type="checkbox" value="1" @checked(old('creation_enabled',$settings['enabled']))><span aria-hidden="true"></span></span></label>
        <label class="settings-field">
            <span>{{ __('Maximum accounts per CMS user') }}</span>
            <input type="number" name="max_accounts" min="1" max="50" value="{{ old('max_accounts',$settings['max_accounts']) }}" required>
            <small data-game-account-limit-help>
                {{ __('The limit is counted across all configured LoginServers.') }}<br>
                {{ __('Temporarily unavailable game accounts also count toward the limit.') }}
            </small>
        </label>
    </section>

    <section class="form-card settings-narrow-card">
        <div class="settings-card-heading"><div><h2>{{ __('Game login policy') }}</h2><p>{{ __('Game logins always allow only Latin letters and digits.') }}</p></div></div>
        <div class="settings-grid two-columns"><label class="settings-field"><span>{{ __('Minimum length') }}</span><input type="number" name="login_min" min="1" max="45" value="{{ old('login_min',$settings['login_min']) }}" required></label><label class="settings-field"><span>{{ __('Maximum length') }}</span><input type="number" name="login_max" min="1" max="45" value="{{ old('login_max',$settings['login_max']) }}" required></label></div>
        <label class="settings-toggle-row" for="login_digit"><span><strong>{{ __('Require a digit') }}</strong></span><span class="switch-control"><input name="login_digit" type="hidden" value="0"><input id="login_digit" name="login_digit" type="checkbox" value="1" @checked(old('login_digit',$settings['login_digit']))><span aria-hidden="true"></span></span></label>
    </section>

    <section class="form-card settings-narrow-card">
        <div class="settings-card-heading"><div><h2>{{ __('Game password policy') }}</h2><p>{{ __('The policy is used both during account creation and password changes.') }}</p></div></div>
        <div class="settings-grid two-columns"><label class="settings-field"><span>{{ __('Minimum length') }}</span><input type="number" name="password_min" min="1" max="45" value="{{ old('password_min',$settings['password_min']) }}" required></label><label class="settings-field"><span>{{ __('Maximum length') }}</span><input type="number" name="password_max" min="1" max="45" value="{{ old('password_max',$settings['password_max']) }}" required></label></div>
        @foreach(['password_lower' => __('Require a lowercase letter'), 'password_upper' => __('Require an uppercase letter'), 'password_digit' => __('Require a digit')] as $field => $label)
            <label class="settings-toggle-row" for="{{ $field }}"><span><strong>{{ $label }}</strong></span><span class="switch-control"><input name="{{ $field }}" type="hidden" value="0"><input id="{{ $field }}" name="{{ $field }}" type="checkbox" value="1" @checked(old($field,$settings[$field]))><span aria-hidden="true"></span></span></label>
        @endforeach
    </section>
    <div class="admin-actions-panel settings-actions settings-actions-narrow"><button class="button button-primary" type="submit">{{ __('Save settings') }}</button></div>
</form>
@endsection
