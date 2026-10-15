@extends('admin.layouts.panel')
@section('title', __('Wallets'))
@section('description', __('CMS user wallets and virtual coin balances. All changes are recorded in the ledger.'))
@section('content')
<div class="admin-page-toolbar"><a wire:navigate class="button button-secondary" href="{{ route('admin.settings.wallet-appearance') }}">{{ __('Currency appearance') }}</a></div>
<x-admin.filter-bar :action="route('admin.wallets.index')">
    <div><label for="wallet-search">{{ __('Search') }}</label><input id="wallet-search" type="search" name="q" value="{{ $search }}" maxlength="100" placeholder="{{ __('Username, email or user ID') }}"></div>
    <div><label for="wallet-filter">{{ __('Wallet status') }}</label><select name="status" id="wallet-filter"><option value="">{{ __('All') }}</option><option value="available" @selected($status === 'available')>{{ __('Available') }}</option><option value="blocked" @selected($status === 'blocked')>{{ __('Blocked') }}</option></select></div>
    <button class="button button-primary" type="submit">{{ __('Apply') }}</button>
    @if($search !== '' || $status !== '')<a wire:navigate class="button button-secondary" href="{{ route('admin.wallets.index') }}">{{ __('Reset') }}</a>@endif
</x-admin.filter-bar>
@if($users->isEmpty())
    <div class="admin-empty-state empty-state"><h2>{{ __('No wallets found') }}</h2><p>{{ __('Try a different search or filter.') }}</p></div>
@else
    <div class="admin-table-wrap audit-table-wrap">
        <table class="admin-table"><thead><tr><th>{{ __('User') }}</th><th>Email</th><th>{{ __('Balance') }}</th><th>{{ __('Wallet status') }}</th><th>{{ __('Actions') }}</th></tr></thead><tbody>
        @foreach($users as $user)
            <tr data-testid="admin-wallet-row">
                <td data-label="{{ __('User') }}"><strong>{{ $user->name }}</strong><span class="audit-muted">ID {{ $user->id }}</span></td>
                <td data-label="Email">{{ $user->email }}</td>
                <td data-label="{{ __('Balance') }}"><strong>{{ number_format($user->wallet?->balance ?? 0, 0, '.', ' ') }}</strong> @if($appearance['icon_url'])<img src="{{ $appearance['icon_url'] }}" width="20" height="20" alt="">@else 🪙 @endif</td>
                <td data-label="{{ __('Wallet status') }}"><span @class(['status-badge', 'status-badge-danger' => $user->wallet?->is_blocked, 'status-badge-success' => ! $user->wallet?->is_blocked])>{{ $user->wallet?->is_blocked ? __('Blocked') : __('Available') }}</span></td>
                <td data-label="{{ __('Actions') }}"><a wire:navigate class="button button-secondary" href="{{ route('admin.wallets.show', $user) }}">{{ __('Details') }}</a></td>
            </tr>
        @endforeach
        </tbody></table>
    </div>
    <x-admin.pagination :paginator="$users" :aria-label="__('Wallet page navigation')" :pages-label="__('Pages')" :previous-label="__('Back')" :next-label="__('Next')" numbered />
@endif
@endsection
