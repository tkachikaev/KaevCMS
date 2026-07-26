@extends('account-theme::layouts.app')
@section('title', __('Account settings'))
@section('inline-validation-errors', '1')
@section('content')
<section class="account-page-heading account-settings-heading">
    <div>
        <span class="account-eyebrow">{{ __('Your account') }}</span>
        <h1>{{ __('Account settings') }}</h1>
        <p>{{ __('Manage your KaevCMS avatar and account information.') }}</p>
    </div>
    <a wire:navigate class="account-button secondary" href="{{ public_route('account') }}">{{ __('Back to overview') }}</a>
</section>

@include('account-theme::partials.settings-tabs')

<section class="account-section account-settings-card account-settings-card-single">
    <div class="account-settings-card-heading">
        <div>
            <span class="account-eyebrow">{{ __('Profile') }}</span>
            <h2>{{ __('Account information') }}</h2>
            <p>{{ __('This avatar and information identify your KaevCMS account only.') }}</p>
        </div>
        <button type="button" class="account-profile-preview-button" data-avatar-modal-open aria-label="{{ __('Change avatar') }}">
            <x-account-avatar :user="$user" class="account-profile-preview" aria-hidden="true" />
            <span aria-hidden="true">✎</span>
        </button>
    </div>

    <dl class="account-settings-details">
        <div><dt>{{ __('Username') }}</dt><dd>{{ $user->name }}</dd></div>
        <div><dt>{{ __('Email address') }}</dt><dd>{{ $user->email }}</dd></div>
        <div><dt>{{ __('Email status') }}</dt><dd>{{ $user->hasVerifiedEmail() ? __('Verified') : __('Not verified') }}</dd></div>
    </dl>

    <div class="account-form-actions account-settings-actions">
        <button type="button" class="account-button primary" data-avatar-modal-open>{{ __('Change avatar') }}</button>
    </div>
</section>
@endsection
