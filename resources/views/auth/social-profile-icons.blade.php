@if ($linkedSocialProviders !== [])
    <div>
        <dt>{{ __('Connected social accounts') }}</dt>
        <dd class="account-profile-social-icons">
            @foreach ($linkedSocialProviders as $provider => $name)
                <a href="{{ public_route('security.edit') }}#account-social-heading" title="{{ $name }}" aria-label="{{ __('Manage :provider account', ['provider' => $name]) }}">
                    <img src="{{ asset('assets/images/social/'.$provider.'.svg') }}" width="18" height="18" alt="">
                </a>
            @endforeach
        </dd>
    </div>
@endif
