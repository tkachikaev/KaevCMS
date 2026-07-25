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
<form class="admin-filter-bar users-filters" method="GET" action="{{ route('admin.users.index') }}">
    <div class="users-search-field"><label for="users-search">{{ __('Search') }}</label><input id="users-search" type="search" name="q" value="{{ $search }}" maxlength="100" placeholder="{{ __('Username or email') }}"></div>
    <div><label for="users-status">{{ __('Status') }}</label><select id="users-status" name="status"><option value="">{{ __('All') }}</option><option value="active" @selected($activeStatus === 'active')>{{ __('Active') }}</option><option value="inactive" @selected($activeStatus === 'inactive')>{{ __('Disabled') }}</option></select></div>
    <div><label for="users-verification">Email</label><select id="users-verification" name="verification"><option value="">{{ __('Any status') }}</option><option value="verified" @selected($activeVerification === 'verified')>{{ __('Verified') }}</option><option value="unverified" @selected($activeVerification === 'unverified')>{{ __('Not verified') }}</option></select></div>
    <button class="button button-primary" type="submit">{{ __('Apply') }}</button>
    @if ($search !== '' || $activeStatus !== '' || $activeVerification !== '')<a wire:navigate class="button button-secondary" href="{{ route('admin.users.index') }}">{{ __('Reset') }}</a>@endif
</form>
@if ($users->isEmpty())
    <div class="admin-empty-state empty-state"><div class="empty-state-mark" aria-hidden="true">U</div><h2>{{ __('No users found') }}</h2><p>{{ __('Change the filters or wait for the first website registration.') }}</p>@if($search !== '' || $activeStatus !== '' || $activeVerification !== '')<a wire:navigate class="button button-secondary" href="{{ route('admin.users.index') }}">{{ __('Show all') }}</a>@endif</div>
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
    @if ($users->hasPages())
        @php($firstPage = max(1, $users->currentPage() - 2))
        @php($lastPage = min($users->lastPage(), $users->currentPage() + 2))
        <nav class="simple-pagination" aria-label="{{ __('User page navigation') }}">
            @if($users->onFirstPage())<span class="button button-secondary disabled">← {{ __('Back') }}</span>@else<a wire:navigate class="button button-secondary" href="{{ $users->previousPageUrl() }}" rel="prev">← {{ __('Back') }}</a>@endif
            <div class="pagination-pages" aria-label="{{ __('Pages') }}">@foreach($users->getUrlRange($firstPage,$lastPage) as $page=>$url) @if($page===$users->currentPage())<span class="pagination-page active" aria-current="page">{{ $page }}</span>@else<a wire:navigate class="pagination-page" href="{{ $url }}">{{ $page }}</a>@endif @endforeach</div>
            @if($users->hasMorePages())<a wire:navigate class="button button-secondary" href="{{ $users->nextPageUrl() }}" rel="next">{{ __('Next') }} →</a>@else<span class="button button-secondary disabled">{{ __('Next') }} →</span>@endif
        </nav>
    @endif
@endif
@endsection
