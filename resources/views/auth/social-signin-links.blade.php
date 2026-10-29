@if ($socialMethods !== [])
<div class="account-settings-card-heading account-panel-header">
    <div>
        <span class="account-eyebrow">{{ __('Sign-in methods') }}</span>
        <h2>{{ __('Connected social accounts') }}</h2>
        <p>{{ __('Social accounts never link automatically by email. You can connect one account per provider.') }}</p>
    </div>
</div>
@foreach ($socialMethods as $provider => $method)
    <div class="account-form-card account-social-form">
        <h3>{{ $method['name'] }}</h3>
        @if ($method['linked'])
            <p>{{ __('Connected') }}</p>
            <form method="POST" action="{{ public_route('security.social.'.$provider.'.unlink') }}">
                @csrf
                @method('DELETE')
                @if ($user->password_set_at !== null)
                    <label for="social_unlink_{{ $provider }}"><span>{{ __('Current password') }}</span>
                        <input id="social_unlink_{{ $provider }}" type="password" name="current_password" autocomplete="current-password" required>
                    </label>
                @else
                    <p>{{ __('A recent social sign-in is needed to change connected accounts.') }}</p>
                @endif
                <button type="submit" class="account-button">{{ __('Disconnect') }} {{ $method['name'] }}</button>
            </form>
        @elseif ($method['available'])
            <form method="POST" action="{{ public_route('security.social.'.$provider.'.link') }}">
                @csrf
                @if ($user->password_set_at !== null)
                    <label for="social_link_{{ $provider }}"><span>{{ __('Current password') }}</span>
                        <input id="social_link_{{ $provider }}" type="password" name="current_password" autocomplete="current-password" required>
                    </label>
                @else
                    <p>{{ __('A recent social sign-in is needed to connect another provider.') }}</p>
                @endif
                <button type="submit" class="account-button primary">{{ __('Connect') }} {{ $method['name'] }}</button>
            </form>
        @else
            <p>{{ __('This sign-in method is not currently available on this site.') }}</p>
        @endif
    </div>
@endforeach
@endif
@if ($user->password_set_at === null)
    <p>{{ __('You have not set a CMS password. You can set one using email password recovery.') }}</p>
@endif
