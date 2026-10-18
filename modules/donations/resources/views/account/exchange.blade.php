@extends('account-theme::layouts.app')
@section('title', __('module-donations::messages.exchange_title'))
@section('description', __('module-donations::messages.exchange_account_help'))
@section('content')
<x-account-page>
    <x-account-panel class="account-section donations-exchange-page" data-testid="donations-game-exchange">
        @include('module-donations::account.exchange-form')
    </x-account-panel>
</x-account-page>
@endsection
