@if (app(\App\Services\Auth\Social\SocialAuthSettings::class)->isAvailable('google'))
    <div class="social-signin-section">
        <p class="muted">{{ __('Or continue with') }}</p>
        <a class="button social-google-button" href="{{ route('social.google.start') }}" rel="nofollow" aria-label="{{ __('Continue with Google') }}">
            <span class="social-google-mark" aria-hidden="true">G</span>
            {{ __('Continue with Google') }}
        </a>
    </div>
@endif
