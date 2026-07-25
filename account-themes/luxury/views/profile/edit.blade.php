@extends('account-theme::layouts.app')
@section('title', __('Profile'))
@section('content')
<section class="account-page-hero account-profile-page-hero account-profile-page-simple">
    <div>
        <span class="account-eyebrow">{{ __('Player profile') }}</span>
        <h1>{{ __('Profile avatar') }}</h1>
        <p>{{ __('Your profile avatar identifies your KaevCMS account only. Game accounts use a neutral icon, while characters keep their own race and class avatars.') }}</p>
        <div class="account-hero-actions">
            <button type="button" class="account-button primary" data-avatar-modal-open>{{ __('Change avatar') }}</button>
            <a wire:navigate class="account-button secondary" href="{{ public_route('account') }}">{{ __('Back to overview') }}</a>
        </div>
    </div>
    <button type="button" class="account-profile-preview-button" data-avatar-modal-open aria-label="{{ __('Change avatar') }}">
        <x-account-avatar :user="$user" class="account-profile-preview" aria-hidden="true" />
        <span aria-hidden="true">✎</span>
    </button>
</section>

<section class="account-section account-profile-explanation">
    <div class="account-profile-rule-grid">
        <article>
            <span aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"></circle><path d="M4.5 20a7.5 7.5 0 0 1 15 0"></path></svg></span>
            <div><strong>{{ __('Profile avatar') }}</strong><small>{{ __('Shown in the KaevCMS header and profile area.') }}</small></div>
        </article>
        <article>
            <span aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="14" rx="4"></rect><path d="M8 12h4M10 10v4M16.5 10.5h.01M18 13h.01"></path></svg></span>
            <div><strong>{{ __('Game accounts') }}</strong><small>{{ __('Use a neutral technical icon and never inherit the profile avatar.') }}</small></div>
        </article>
        <article>
            <span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 8 8l1 4-3 3 6 6 6-6-3-3 1-4z"></path></svg></span>
            <div><strong>{{ __('Characters') }}</strong><small>{{ __('Use their own race, gender and archetype images.') }}</small></div>
        </article>
    </div>
</section>
@endsection
