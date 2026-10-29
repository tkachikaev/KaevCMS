<div class="account-settings-card-heading account-panel-header">
    <div>
        <span class="account-eyebrow">{{ __('Sign-in methods') }}</span>
        <h2>{{ __('Google sign-in') }}</h2>
        <p>{{ __('Link one Google account to your CMS account. Google never links automatically by email.') }}</p>
    </div>
</div>
<div class="account-form-card account-social-form">
    @if ($googleLinked)
        <p>{{ __('Google is connected.') }}</p>
        <form method="POST" action="{{ public_route('security.social.google.unlink') }}">
            @csrf
            @method('DELETE')
            @if ($user->password_set_at !== null)
                <label for="google_unlink_password"><span>{{ __('Current password') }}</span>
                    <input id="google_unlink_password" type="password" name="current_password" autocomplete="current-password" required>
                </label>
            @else
                <p>{{ __('A recent social sign-in is needed to change connected accounts.') }}</p>
            @endif
            <button type="submit" class="account-button">{{ __('Disconnect Google') }}</button>
        </form>
    @elseif ($googleAvailable)
        <form method="POST" action="{{ public_route('security.social.google.link') }}">
            @csrf
            @if ($user->password_set_at !== null)
                <label for="google_link_password"><span>{{ __('Current password') }}</span>
                    <input id="google_link_password" type="password" name="current_password" autocomplete="current-password" required>
                </label>
            @else
                <p>{{ __('A recent social sign-in is needed to connect another provider.') }}</p>
            @endif
            <button type="submit" class="account-button primary">{{ __('Connect Google') }}</button>
        </form>
    @else
        <p>{{ __('Google sign-in is not currently available on this site.') }}</p>
    @endif
    @if ($user->password_set_at === null)
        <p>{{ __('You have not set a CMS password. You can set one using email password recovery.') }}</p>
    @endif
</div>
