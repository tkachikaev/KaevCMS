@extends('account-theme::layouts.app')
@section('title', __('My characters'))
@section('content')
<section class="account-character-page">
    <div class="account-character-page-actions">
        <a wire:navigate class="account-button secondary" href="{{ public_route('game-accounts.index') }}">{{ __('Manage accounts') }}</a>
    </div>
    <livewire:account.character-directory />
</section>
@endsection
