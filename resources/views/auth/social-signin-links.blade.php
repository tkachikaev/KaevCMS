@if ($socialMethods !== [])
<section class="account-social-section" aria-labelledby="account-social-heading">
    <div class="account-panel-header">
        <div>
            <h2 id="account-social-heading">{{ __('Connected social accounts') }}</h2>
            <p>{{ __('Connect a service to sign in to your account more easily.') }}</p>
        </div>
    </div>
    <div class="account-social-list">
        @foreach ($socialMethods as $provider => $method)
            <div class="account-social-row" data-account-social="{{ $provider }}">
                <div class="account-social-row-head">
                    <span class="account-social-logo" aria-hidden="true"><img src="{{ asset('assets/images/social/'.$provider.'.svg') }}" width="22" height="22" alt=""></span>
                    <span class="account-social-name">{{ $method['name'] }}</span>
                    <span @class(['account-social-status', 'is-connected' => $method['linked']])>{{ $method['linked'] ? __('Account connected') : __('Account not connected') }}</span>
                    <button type="button" data-account-social-open="account-social-{{ $provider }}" aria-haspopup="dialog" @class(['account-social-action', 'is-disconnect' => $method['linked']])>{{ $method['linked'] ? __('Disconnect') : __('Connect') }}</button>
                </div>
                <dialog id="account-social-{{ $provider }}" class="account-social-dialog" data-account-social-dialog aria-labelledby="account-social-title-{{ $provider }}">
                    <header class="account-social-dialog-header">
                        <span class="account-social-logo" aria-hidden="true"><img src="{{ asset('assets/images/social/'.$provider.'.svg') }}" width="22" height="22" alt=""></span>
                        <h2 id="account-social-title-{{ $provider }}">{{ $method['linked'] ? __('Disconnect') : __('Connect') }} {{ $method['name'] }}</h2>
                        <button type="button" class="account-avatar-modal-close" data-account-social-close aria-label="{{ __('Close') }}">×</button>
                    </header>
                    <div class="account-social-confirm">
                    <form method="POST" action="{{ public_route('security.social.'.$provider.($method['linked'] ? '.unlink' : '.link')) }}" class="account-form-card">
                        @csrf
                        @if ($method['linked'])
                            @method('DELETE')
                        @endif
                        @if ($user->password_set_at !== null)
                            <label for="social_{{ $provider }}_password">
                                <span>{{ __('Current password') }}</span>
                                <input id="social_{{ $provider }}_password" type="password" name="current_password" autocomplete="current-password" required autofocus>
                                <small>{{ __('Enter your website password to confirm this change.') }}</small>
                            </label>
                        @else
                            <p>{{ $method['linked'] ? __('A recent social sign-in is needed to change connected accounts.') : __('A recent social sign-in is needed to connect another provider.') }}</p>
                        @endif
                        <div class="account-social-dialog-actions">
                        <button type="submit" @class(['account-button', 'primary' => ! $method['linked'], 'secondary' => $method['linked']])>{{ $method['linked'] ? __('Disconnect') : __('Connect') }} {{ $method['name'] }}</button>
                        </div>
                    </form>
                    @unless ($method['available'])
                        <p class="account-social-unavailable">{{ __('This sign-in method is not currently available on this site.') }}</p>
                    @endunless
                    </div>
                </dialog>
            </div>
        @endforeach
    </div>
</section>
@endif
@if ($user->password_set_at === null)
    <p>{{ __('You have not set a CMS password. You can set one using email password recovery.') }}</p>
@endif
