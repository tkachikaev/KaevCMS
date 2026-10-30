@extends('admin.layouts.panel')

@section('title', __('Registration'))
@section('description', __('Registration for public website users.'))

@section('content')
@include('admin.settings._system_tabs')

<form id="registration-settings-form" class="settings-form" data-registration-save method="POST" action="{{ route('admin.settings.registration.update') }}">
    @csrf
    @method('PUT')

    <section class="form-card settings-narrow-card">
        <div class="settings-card-heading">
            <div>
                <h2>{{ __('User registration') }}</h2>
                <p>{{ __('These accounts belong to the website only. Lineage II game accounts are created separately.') }}</p>
            </div>
        </div>

        <x-admin.toggle
            id="registration_enabled"
            name="registration_enabled"
            :label="__('Allow new user registration')"
            :hint="__('Shows the registration button and opens the website account creation page.')"
            :checked="(bool) old('registration_enabled', $settings['enabled'])"
        />

        <x-admin.toggle
            id="email_verification_required"
            name="email_verification_required"
            :label="__('Require email verification')"
            :hint="__('The user can access the account area only after opening the link from the email.')"
            :checked="(bool) old('email_verification_required', $settings['email_verification_required'])"
        />

        @if($mailReady)
            <div class="notice notice-success settings-inline-notice">
                <p><strong>{{ __('Mail is verified.') }}</strong> {{ __('Email verification may be enabled.') }}</p>
            </div>
        @else
            <div class="notice notice-warning settings-inline-notice">
                <p><strong>{{ __('Mail is not verified yet.') }}</strong> {{ __('Save SMTP settings and send a test email before enabling verified registration.') }}</p>
                <p><a wire:navigate href="{{ route('admin.settings.mail') }}">{{ __('Open mail settings') }} →</a></p>
            </div>
        @endif
    </section>

    <section class="form-card settings-narrow-card">
        <div class="settings-card-heading">
            <div>
                <h2>{{ __('Website username policy') }}</h2>
                <p>{{ __('Latin letters and digits are always allowed. Configure the length and optional separators.') }}</p>
            </div>
        </div>

        <div class="security-field-grid">
            <x-admin.field for="username_min" name="username_min" :label="__('Minimum length')" :hint="__('Allowed range: 3 to 32.')">
                <input id="username_min" name="username_min" type="number" min="3" max="32" required value="{{ old('username_min', $settings['username_min']) }}" @if($errors->has('username_min')) aria-invalid="true" @endif>
            </x-admin.field>
            <x-admin.field for="username_max" name="username_max" :label="__('Maximum length')" :hint="__('Allowed range: 3 to 64. Must not be less than the minimum.')">
                <input id="username_max" name="username_max" type="number" min="3" max="64" required value="{{ old('username_max', $settings['username_max']) }}" @if($errors->has('username_max')) aria-invalid="true" @endif>
            </x-admin.field>
        </div>

        @foreach([
            'username_allow_hyphen' => [__('Allow hyphen'), __('Users may include the - character in the website username.')],
            'username_allow_underscore' => [__('Allow underscore'), __('Users may include the _ character in the website username.')],
        ] as $field => [$label, $help])
            <x-admin.toggle :id="$field" :name="$field" :label="$label" :hint="$help" :checked="(bool) old($field, $settings[$field])" />
        @endforeach
    </section>

    <section class="form-card settings-narrow-card">
        <div class="settings-card-heading">
            <div>
                <h2>{{ __('Website password policy') }}</h2>
                <p>{{ __('The policy applies to registration, password reset and password changes in the player account.') }}</p>
            </div>
        </div>

        <div class="security-field-grid registration-policy-single-field">
            <x-admin.field for="password_min" name="password_min" :label="__('Minimum length')" :hint="__('Allowed range: 8 to 64.')">
                <input id="password_min" name="password_min" type="number" min="8" max="64" required value="{{ old('password_min', $settings['password_min']) }}" @if($errors->has('password_min')) aria-invalid="true" @endif>
            </x-admin.field>
        </div>

        @foreach([
            'password_letters' => [__('Require a letter'), __('At least one Latin letter is required unless mixed case is enabled.')],
            'password_mixed_case' => [__('Require uppercase and lowercase letters'), __('The password must contain both uppercase and lowercase letters.')],
            'password_numbers' => [__('Require a digit'), __('The password must contain at least one digit.')],
            'password_symbols' => [__('Require a symbol'), __('The password must contain at least one symbol.')],
        ] as $field => [$label, $help])
            <x-admin.toggle :id="$field" :name="$field" :label="$label" :hint="$help" :checked="(bool) old($field, $settings[$field])" />
        @endforeach
    </section>

</form>

<section class="form-card settings-narrow-card social-auth-settings" aria-labelledby="social-auth-title">
    <div class="settings-card-heading">
        <div>
            <h2 id="social-auth-title">{{ __('Social sign-in') }}</h2>
            <p>{{ __('Configure which services players can use to sign in. Existing website passwords and administrator access are unchanged.') }}</p>
        </div>
    </div>

    @unless($mailReady)
        <div class="notice notice-warning settings-inline-notice">
            <p>{{ __('Email delivery is not verified. Players who register through social services may be unable to verify their email or recover access.') }}</p>
        </div>
    @endunless

    <div class="social-provider-list">
        @foreach($socialProviders as $provider => $social)
            <div class="social-provider-item" data-social-provider="{{ $provider }}">
                <div class="social-provider-head">
                    <span class="social-provider-mark" aria-hidden="true"><img src="{{ asset('assets/images/social/'.$provider.'.svg') }}" width="20" height="20" alt=""></span>
                    <span class="social-provider-copy">
                        <strong>{{ $social['name'] }}</strong>
                    </span>
                    <span data-social-badge data-labels="{{ json_encode(['enabled' => __('Enabled'), 'ready' => __('Ready to enable'), 'invalid' => __('Configuration error'), 'pending' => __('JWT verification library required'), 'unconfigured' => __('Not configured')]) }}" @class([
                        'status-badge',
                        'status-badge-success' => $social['status'] === 'enabled',
                        'status-badge-warning' => in_array($social['status'], ['invalid', 'ready', 'pending'], true),
                        'status-badge-muted' => $social['status'] === 'unconfigured',
                    ])>{{ __([
                        'enabled' => 'Enabled',
                        'ready' => 'Ready to enable',
                        'invalid' => 'Configuration error',
                        'pending' => 'JWT verification library required',
                        'unconfigured' => 'Not configured',
                    ][$social['status']]) }}</span>
                </div>

                <div class="social-provider-quick-actions">
                    <button type="button" class="button button-secondary" data-social-open="social-{{ $provider }}" aria-haspopup="dialog">{{ __('Configure') }}</button>
                    <div data-social-toggle>
                        <x-admin.toggle :id="'social-enabled-'.$provider" :name="'social_providers['.$provider.']'" :label="__('Enable sign-in')" :checked="(bool) old('social_providers.'.$provider, $social['enabled'])" :disabled="! $social['enabled'] && (! $social['configured'] || ! $social['integration_ready'])" :compact="true" :input-attributes="['form' => 'registration-settings-form']" />
                    </div>
                </div>
                <dialog class="social-provider-dialog" id="social-{{ $provider }}" aria-labelledby="social-title-{{ $provider }}" data-social-dialog data-open-on-error="{{ $errors->any() && old('_social_provider') === $provider ? '1' : '0' }}">
                    <header class="social-provider-dialog-heading">
                        <h2 id="social-title-{{ $provider }}">{{ $social['name'] }}</h2>
                        <button type="button" class="button button-secondary" data-social-close aria-label="{{ __('Close') }}">×</button>
                    </header>
                    @if($provider === 'telegram' && ! $social['integration_ready'])
                        <p class="notice notice-warning">{{ __('Telegram sign-in cannot be enabled until a verified JWT/JWKS library is approved and installed.') }}</p>
                    @endif
                        <div class="social-provider-form-wrap">
                            <form method="POST" action="{{ route('admin.settings.registration.social.update', ['provider' => $provider]) }}" autocomplete="off" data-social-save>
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="_social_provider" value="{{ $provider }}">
                                <div class="form-group">
                                    <label for="social-client-{{ $provider }}">{{ $provider === 'vk' ? __('Application ID (VK ID)') : ($provider === 'telegram' ? __('Client ID (BotFather)') : __('Client ID / App ID')) }}</label>
                                    <input id="social-client-{{ $provider }}" name="client_id" type="text" required maxlength="255" value="{{ $social['client_id'] }}" autocomplete="off" spellcheck="false">
                                </div>
                                @if($social['requires_secret'])
                                    <div class="form-group">
                                        <label for="social-secret-{{ $provider }}">{{ __('Client Secret') }}</label>
                                        <input id="social-secret-{{ $provider }}" name="client_secret" type="password" maxlength="2048" value="" autocomplete="new-password" placeholder="{{ $social['secret_saved'] && $social['secret_valid'] ? __('Secret saved') : __('Enter client secret') }}">
                                        <small>{{ $social['secret_saved'] && ! $social['secret_valid'] ? __('Saved secret cannot be decrypted. Enter a new secret.') : __('Leave the field empty to retain the saved secret. Secrets are encrypted and never displayed.') }}</small>
                                    </div>
                                @else
                                    <p class="muted-admin">{{ __('VK ID uses PKCE and does not require a client secret.') }}</p>
                                @endif
                                <div class="form-group">
                                    <label for="social-callback-{{ $provider }}">{{ __('Redirect / callback URL') }}</label>
                                    @if($social['callback_url'])
                                        <div class="social-provider-callback">
                                            <input id="social-callback-{{ $provider }}" type="text" readonly value="{{ $social['callback_url'] }}" aria-label="{{ __('Redirect / callback URL') }}">
                                            <button class="button button-secondary" type="button" data-social-copy="social-callback-{{ $provider }}" data-social-copy-success="{{ __('Copied') }}" data-social-copy-failure="{{ __('Copy failed. Select and copy the URL manually.') }}">{{ __('Copy') }}</button>
                                        </div>
                                        <small>{{ __('Register this exact address with the provider. It comes from APP_URL, not the current browser address.') }}</small>
                                    @else
                                        <p class="notice notice-warning">{{ __('Configure an HTTPS APP_URL before enabling social sign-in. HTTP is allowed only for local development.') }}</p>
                                    @endif
                                </div>
                                <div class="social-provider-form-actions">
                                    <button class="button button-primary" type="submit">{{ __('Save provider settings') }}</button>
                                    <a href="{{ $social['console'] }}" target="_blank" rel="noopener noreferrer">{{ __('Open provider console') }} ↗</a>
                                </div>
                            </form>
                                <form method="POST" action="{{ route('admin.settings.registration.social.secret.clear', ['provider' => $provider]) }}" class="social-provider-remove-secret" data-social-save @if(! $social['secret_saved']) hidden @endif>
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="button button-secondary">{{ __('Remove saved secret and disable sign-in') }}</button>
                                </form>
                            <p class="muted-admin">{{ __('Changing the Client ID disables sign-in until it is enabled again. Existing player links are never deleted.') }}</p>
                        </div>
                </dialog>
            </div>
        @endforeach
    </div>
</section>
<div class="admin-actions-panel settings-actions settings-actions-narrow">
    <button class="button button-primary" type="submit" form="registration-settings-form">{{ __('Save settings') }}</button>
</div>
<p data-social-message role="status" data-save-error="{{ __('Could not save settings. Please try again.') }}" data-secret-saved="{{ __('Secret saved') }}"></p>
<script src="{{ asset('assets/admin/js/social-providers.js') }}?v={{ cms_version() }}&ui=social-save" defer data-navigate-once></script>
@endsection
