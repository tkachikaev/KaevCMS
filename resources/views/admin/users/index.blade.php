@extends('admin.layouts.panel')
@section('title', __('Users'))
@section('description', __('Public website accounts. Game accounts and characters are connected separately.'))
@section('content')
<div class="admin-overview users-summary">
    <div class="admin-overview-stat content-stat"><span>{{ __('Total') }}</span><strong>{{ $totalCount }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('Active') }}</span><strong>{{ $activeCount }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('Disabled') }}</span><strong>{{ $inactiveCount }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('Unverified') }}</span><strong>{{ $unverifiedCount }}</strong></div>
    <p class="admin-overview-copy">{{ __('This section manages CMS users only. It does not create or modify Login Server accounts.') }}</p>
</div>
<x-admin.filter-bar :action="route('admin.users.index')" class="users-filters">
    <div class="users-search-field"><label for="users-search">{{ __('Search') }}</label><input id="users-search" type="search" name="q" value="{{ $search }}" maxlength="100" placeholder="{{ __('Username or email') }}"></div>
    <div><label for="users-status">{{ __('Status') }}</label><select id="users-status" name="status"><option value="">{{ __('All') }}</option><option value="active" @selected($activeStatus === 'active')>{{ __('Active') }}</option><option value="inactive" @selected($activeStatus === 'inactive')>{{ __('Disabled') }}</option></select></div>
    <div><label for="users-verification">Email</label><select id="users-verification" name="verification"><option value="">{{ __('Any status') }}</option><option value="verified" @selected($activeVerification === 'verified')>{{ __('Verified') }}</option><option value="unverified" @selected($activeVerification === 'unverified')>{{ __('Not verified') }}</option></select></div>
    <x-admin.button type="submit" variant="primary">{{ __('Apply') }}</x-admin.button>
    @if ($search !== '' || $activeStatus !== '' || $activeVerification !== '')<x-admin.button wire:navigate :href="route('admin.users.index')">{{ __('Reset') }}</x-admin.button>@endif
</x-admin.filter-bar>
@if ($users->isEmpty())
    <div class="admin-empty-state empty-state"><div class="empty-state-mark" aria-hidden="true">U</div><h2>{{ __('No users found') }}</h2><p>{{ __('Change the filters or wait for the first website registration.') }}</p>@if($search !== '' || $activeStatus !== '' || $activeVerification !== '')<x-admin.button wire:navigate :href="route('admin.users.index')">{{ __('Show all') }}</x-admin.button>@endif</div>
@else
    <div class="admin-card-list users-list">
        <div class="admin-card-list-header user-row user-row-header"><span>{{ __('User') }}</span><span>Email</span><span>{{ __('Registered') }}</span><span>{{ __('Last sign in') }}</span><span>{{ __('Status') }}</span><span></span></div>
        @foreach ($users as $user)
            <article class="admin-card-row user-row">
                <div class="user-list-identity"><strong>{{ $user->name }}</strong><small>ID {{ $user->id }}</small></div>
                <div class="user-list-email"><span>{{ $user->email }}</span><small @class(['verified' => $user->hasVerifiedEmail(),'unverified' => ! $user->hasVerifiedEmail()])>{{ $user->hasVerifiedEmail() ? __('Email verified') : __('Email not verified') }}</small></div>
                <time datetime="{{ $user->created_at?->toAtomString() }}">{{ $user->created_at?->format('d.m.Y H:i') ?? '—' }}</time>
                <time datetime="{{ $user->last_login_at?->toAtomString() }}">{{ $user->last_login_at?->format('d.m.Y H:i') ?? __('Never') }}</time>
                <div><span @class(['status-badge','status-badge-success' => $user->is_active,'status-badge-muted' => ! $user->is_active])>{{ $user->is_active ? __('Active') : __('Disabled') }}</span></div>
                <div class="admin-row-actions user-list-action"><a wire:navigate class="button button-secondary" href="{{ route('admin.users.show', $user) }}">{{ __('Details') }}</a></div>
            </article>
        @endforeach
    </div>
    <x-admin.pagination
        :paginator="$users"
        :aria-label="__('User page navigation')"
        :pages-label="__('Pages')"
        :previous-label="__('Back')"
        :next-label="__('Next')"
        numbered
    />
@endif
@endsection
