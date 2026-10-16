@extends('account-theme::layouts.app')
@section('title', __('Account settings'))
@section('description', __('Manage your KaevCMS avatar and account information.'))
@section('inline-validation-errors', '1')
@section('content')
<x-account-page>

<x-account-panel class="account-section account-settings-card account-settings-card-single">
    @include('account-theme::partials.settings-tabs')

    <div class="account-settings-card-heading account-panel-header">
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
</x-account-panel>
</x-account-page>
@endsection
