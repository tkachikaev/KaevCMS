@php
    $availableSocialMethods = collect(['google' => ['name' => 'Google'], 'yandex' => ['name' => 'Яндекс ID'], 'vk' => ['name' => 'VK ID'], 'telegram' => ['name' => 'Telegram'], 'discord' => ['name' => 'Discord'], 'facebook' => ['name' => 'Facebook']])
        ->filter(fn (array $method, string $provider) => app(\App\Services\Auth\Social\SocialAuthSettings::class)->isAvailable($provider));
@endphp
@if ($availableSocialMethods->isNotEmpty())
    <div class="social-signin-section">
        <p class="muted">{{ __('Or continue with') }}</p>
        <div class="social-signin-options">
            @foreach ($availableSocialMethods as $provider => $method)
                <a class="social-signin-button" href="{{ route('social.'.$provider.'.start') }}" rel="nofollow" aria-label="{{ __('Continue with :provider', ['provider' => $method['name']]) }}">
                    <img class="social-signin-icon" src="{{ asset('assets/images/social/'.$provider.'.svg') }}" width="18" height="18" alt="" aria-hidden="true">
                    <span>{{ $method['name'] }}</span>
                </a>
            @endforeach
        </div>
    </div>
@endif
