@extends('account-theme::layouts.app')
@section('title', __('Create game account'))
@section('inline-validation-errors', '1')
@section('content')
<div class="account-page-heading">
    <a wire:navigate href="{{ public_route('game-accounts.index') }}">← {{ __('My accounts') }}</a>
    <span class="account-eyebrow">{{ __('New access') }}</span>
    <h1>{{ __('Create game account') }}</h1>
    <p>{{ __('Choose a game world and set the login credentials. The form contains only the required fields.') }}</p>
</div>

<div class="account-form-layout">
    <form class="account-form-card" method="POST" action="{{ public_route('game-accounts.store') }}">
        @csrf
        <div class="account-form-title"><span aria-hidden="true">01</span><div><h2>{{ __('Account details') }}</h2><p>{{ __('Credentials are written directly to the selected LoginServer.') }}</p></div></div>
        <label>
            <span>{{ __('Game server') }}</span>
            <div class="account-field-control">
                <select name="game_server_id" required @class(['account-field-invalid' => $errors->has('game_server_id')]) aria-describedby="game-server-help @error('game_server_id') game-server-error @enderror">
                    <option value="">{{ __('Select a game server') }}</option>
                    @foreach ($gameServers as $server)
                        <option value="{{ $server->id }}" @selected((int) old('game_server_id') === $server->id)>{{ $server->nameFor() }}@if($server->rates) — {{ $server->rates }}@endif</option>
                    @endforeach
                </select>
                @error('game_server_id')<small class="account-field-error" id="game-server-error" role="alert">{{ $message }}</small>@enderror
            </div>
            <small id="game-server-help">{{ __('The selected world determines the LoginServer where the account will be created.') }}</small>
        </label>

        <label>
            <span>{{ __('Game login') }}</span>
            <div class="account-field-control">
                <input type="text" name="game_login" value="{{ old('game_login') }}" autocomplete="username" required maxlength="{{ $settings['login_max'] }}" @class(['account-field-invalid' => $errors->has('game_login')]) aria-describedby="game-login-help @error('game_login') game-login-error @enderror">
                @error('game_login')<small class="account-field-error" id="game-login-error" role="alert">{{ $message }}</small>@enderror
            </div>
            <small id="game-login-help">{{ __('Latin letters and digits, from :min to :max characters.', ['min' => $settings['login_min'], 'max' => $settings['login_max']]) }}</small>
        </label>

        <div class="account-form-grid">
            <label><span>{{ __('Game password') }}</span><div class="account-field-control"><input type="password" name="game_password" autocomplete="new-password" required maxlength="{{ $settings['password_max'] }}" @class(['account-field-invalid' => $errors->has('game_password')]) @error('game_password') aria-describedby="game-password-error" @enderror>@error('game_password')<small class="account-field-error" id="game-password-error" role="alert">{{ $message }}</small>@enderror</div></label>
            <label><span>{{ __('Repeat game password') }}</span><div class="account-field-control"><input type="password" name="game_password_confirmation" autocomplete="new-password" required maxlength="{{ $settings['password_max'] }}" @class(['account-field-invalid' => $errors->has('game_password_confirmation')]) @error('game_password_confirmation') aria-describedby="game-password-confirmation-error" @enderror>@error('game_password_confirmation')<small class="account-field-error" id="game-password-confirmation-error" role="alert">{{ $message }}</small>@enderror</div></label>
        </div>

        <div class="account-form-note">
            <strong>{{ __('Password policy') }}</strong>
            <span>{{ __('From :min to :max characters.', ['min' => $settings['password_min'], 'max' => $settings['password_max']]) }}</span>
            @if($settings['password_lower'])<span>{{ __('Lowercase letter required.') }}</span>@endif
            @if($settings['password_upper'])<span>{{ __('Uppercase letter required.') }}</span>@endif
            @if($settings['password_digit'])<span>{{ __('Digit required.') }}</span>@endif
        </div>

        <div class="account-form-actions">
            <a wire:navigate class="account-button secondary" href="{{ public_route('game-accounts.index') }}">{{ __('Cancel') }}</a>
            <button class="account-button primary" type="submit">{{ __('Create account') }}</button>
        </div>
    </form>

    <aside class="account-form-aside">
        <span class="account-form-aside-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 5 7v5c0 4.5 2.8 7.2 7 9 4.2-1.8 7-4.5 7-9V7z"></path><path d="m9 12 2 2 4-4"></path></svg></span>
        <h2>{{ __('Before creating') }}</h2>
        <ul>
            <li>{{ __('Use a unique login that you do not use on other projects.') }}</li>
            <li>{{ __('The game password is stored only by the LoginServer.') }}</li>
            <li>{{ __('Characters will appear automatically after they are created in game.') }}</li>
        </ul>
    </aside>
</div>
@endsection
