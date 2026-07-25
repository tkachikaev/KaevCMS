@extends('admin.layouts.panel')
@section('title', __('Administrators'))
@section('description', __('Control panel accounts with role-based access.'))
@section('content')
<div class="admin-overview administrators-toolbar">
    <div class="admin-overview-stat content-stat"><span>{{ __('Total') }}</span><strong>{{ $totalCount }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('Active') }}</span><strong>{{ $activeCount }}</strong></div>
    <p class="admin-overview-copy">{{ __('Roles limit which control panel sections and actions are available to each account.') }}</p>
    <a wire:navigate class="button button-primary" href="{{ route('admin.administrators.create') }}">{{ __('Create administrator') }}</a>
</div>
<div class="notice notice-warning administrators-notice"><p>{{ __('Administrator accounts are not deleted. Disable unused accounts to preserve their audit history.') }}</p></div>
<div class="admin-card-list administrators-list">
    <div class="admin-card-list-header administrator-row administrator-row-header"><span>{{ __('Administrator') }}</span><span>{{ __('Role') }}</span><span>{{ __('Created') }}</span><span>{{ __('Last sign in') }}</span><span>2FA</span><span>{{ __('Status') }}</span><span>{{ __('Actions') }}</span></div>
    @foreach ($administrators as $administrator)
        @php
            $isCurrent = $currentAdmin?->is($administrator) ?? false;
            $canManageOther = $currentAdmin->isOwner() || ($currentAdmin->role === \App\Auth\AdminRole::Administrator && ! $administrator->isOwner());
            $canEdit = $isCurrent || $canManageOther;
            $ownerProtection = $administrator->isOwner() && $activeOwnerCount <= 1;
            $canDisable = $administrator->is_active && ! $isCurrent && $canManageOther && ! $ownerProtection && $activeCount > 1;
        @endphp
        <article class="admin-card-row administrator-row">
            <div class="administrator-identity"><strong>{{ $administrator->name }}</strong><span>{{ $administrator->email }}</span>@if($isCurrent)<small>{{ __('Current account') }}</small>@endif</div>
            <div class="administrator-role-cell"><span class="administrator-role-badge role-{{ $administrator->role->value }}">{{ $administrator->roleLabel() }}</span></div>
            <time datetime="{{ $administrator->created_at?->toAtomString() }}">{{ $administrator->created_at?->format('d.m.Y H:i') ?? '—' }}</time>
            <time datetime="{{ $administrator->last_login_at?->toAtomString() }}">{{ $administrator->last_login_at?->format('d.m.Y H:i') ?? __('Never') }}</time>
            <div class="administrator-two-factor-status"><span @class(['status-badge','status-badge-success' => $administrator->twoFactorEnabled(),'status-badge-muted' => ! $administrator->twoFactorEnabled()])>{{ $administrator->twoFactorEnabled() ? __('Two-factor status enabled') : __('Two-factor status disabled') }}</span></div>
            <div><span @class(['status-badge','status-badge-success' => $administrator->is_active,'status-badge-muted' => ! $administrator->is_active])>{{ $administrator->is_active ? __('Active') : __('Disabled') }}</span></div>
            <div class="admin-row-actions administrator-actions">
                @if($canEdit)
                    <a wire:navigate class="button button-secondary" href="{{ route('admin.administrators.edit', $administrator) }}">{{ __('Edit') }}</a>
                @endif
                @if ($administrator->is_active)
                    <form method="POST" action="{{ route('admin.administrators.status', $administrator) }}">@csrf
                    @method('PATCH')<input type="hidden" name="is_active" value="0"><button class="button button-danger" type="submit" @disabled(! $canDisable) title="{{ $isCurrent ? __('You cannot disable your own account.') : ($ownerProtection ? __('The last active owner cannot be disabled.') : (! $canManageOther ? __('Your role cannot manage this account.') : ($activeCount <= 1 ? __('The last active administrator cannot be disabled.') : __('Disable administrator')))) }}">{{ __('Disable') }}</button></form>
                @elseif($canManageOther)
                    <form method="POST" action="{{ route('admin.administrators.status', $administrator) }}">@csrf
                    @method('PATCH')<input type="hidden" name="is_active" value="1"><button class="button button-primary" type="submit">{{ __('Enable') }}</button></form>
                @endif
            </div>
        </article>
    @endforeach
</div>
@if ($administrators->hasPages())
    <div class="simple-pagination">
        @if($administrators->onFirstPage())<span class="button button-secondary disabled">← {{ __('Back') }}</span>@else<a wire:navigate class="button button-secondary" href="{{ $administrators->previousPageUrl() }}" rel="prev">← {{ __('Back') }}</a>@endif
        <span class="administrator-page-state">{{ __('Page :current of :last', ['current' => $administrators->currentPage(), 'last' => $administrators->lastPage()]) }}</span>
        @if($administrators->hasMorePages())<a wire:navigate class="button button-secondary" href="{{ $administrators->nextPageUrl() }}" rel="next">{{ __('Next') }} →</a>@else<span class="button button-secondary disabled">{{ __('Next') }} →</span>@endif
    </div>
@endif
@endsection
