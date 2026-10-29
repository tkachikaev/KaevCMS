@php
    $availableSocialMethods = collect(['google' => ['name' => 'Google', 'mark' => 'G'], 'yandex' => ['name' => 'Яндекс ID', 'mark' => 'Я'], 'vk' => ['name' => 'VK ID', 'mark' => 'VK']])
        ->filter(fn (array $method, string $provider) => app(\App\Services\Auth\Social\SocialAuthSettings::class)->isAvailable($provider));
@endphp
@if ($availableSocialMethods->isNotEmpty())
    <div class="social-signin-section">
        <p class="muted">{{ __('Or continue with') }}</p>
        <div class="social-signin-options">
            @foreach ($availableSocialMethods as $provider => $method)
                <a class="button social-google-button social-provider-{{ $provider }}" href="{{ route('social.'.$provider.'.start') }}" rel="nofollow" aria-label="{{ __('Continue with :provider', ['provider' => $method['name']]) }}">
                    <span class="social-google-mark social-provider-mark-{{ $provider }}" aria-hidden="true">{{ $method['mark'] }}</span>
                    {{ __('Continue with :provider', ['provider' => $method['name']]) }}
                </a>
            @endforeach
        </div>
    </div>
@endif
