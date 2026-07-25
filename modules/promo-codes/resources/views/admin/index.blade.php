@extends('admin.layouts.panel')
@section('title', __('module-promo-codes::messages.admin_title'))
@section('description', __('module-promo-codes::messages.admin_description'))
@section('content')
@php($adminPath = request()->route('adminPath'))
<div class="admin-overview content-toolbar">
    <div class="admin-overview-stat content-stat"><span>{{ __('module-promo-codes::messages.total') }}</span><strong>{{ $totalCount }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('module-promo-codes::messages.enabled_count') }}</span><strong>{{ $enabledCount }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('module-promo-codes::messages.disabled_count') }}</span><strong>{{ $disabledCount }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('module-promo-codes::messages.activations') }}</span><strong>{{ number_format($activationsCount, 0, '.', ' ') }}</strong></div>
    <a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.promo-codes.activations', ['adminPath' => $adminPath]) }}">{{ __('module-promo-codes::messages.open_journal') }}</a>
    @if($canManage)
        <a wire:navigate class="button button-primary" href="{{ route('admin.module-pages.promo-codes.create', ['adminPath' => $adminPath]) }}">{{ __('module-promo-codes::messages.create') }}</a>
    @endif
</div>

@if($promoCodes->isEmpty())
    <div class="admin-empty-state empty-state">
        <div class="empty-state-mark">%</div>
        <h2>{{ __('module-promo-codes::messages.empty_title') }}</h2>
        <p>{{ __('module-promo-codes::messages.empty_description') }}</p>
        @if($canManage)
            <a wire:navigate class="button button-primary" href="{{ route('admin.module-pages.promo-codes.create', ['adminPath' => $adminPath]) }}">{{ __('module-promo-codes::messages.create_first') }}</a>
        @endif
    </div>
@else
    <div class="admin-card-list content-list">
        @foreach($promoCodes as $promoCode)
            <article class="admin-card-row content-row">
                <div class="content-row-preview page-row-preview"><span>{{ $promoCode->enabled ? __('module-promo-codes::messages.status_active') : __('module-promo-codes::messages.status_disabled') }}</span></div>
                <div class="content-row-main">
                    <a wire:navigate class="content-row-title" href="{{ route('admin.module-pages.promo-codes.edit', ['adminPath' => $adminPath, 'promoCode' => $promoCode]) }}">{{ $promoCode->code }}</a>
                    <p>{{ $promoCode->gameServer->nameFor() }}</p>
                    <div class="content-row-meta">
                        <span>{{ __('module-promo-codes::messages.period') }}: {{ $promoCode->starts_at?->format('d.m.Y H:i') ?? __('module-promo-codes::messages.immediately') }} — {{ $promoCode->ends_at?->format('d.m.Y H:i') ?? __('module-promo-codes::messages.no_expiration') }}</span>
                        <span>{{ __('module-promo-codes::messages.total_limit_short') }}: {{ $promoCode->total_limit === 0 ? __('module-promo-codes::messages.unlimited') : number_format($promoCode->total_limit, 0, '.', ' ') }}</span>
                        <span>{{ __('module-promo-codes::messages.per_account_short') }}: {{ number_format($promoCode->per_user_limit, 0, '.', ' ') }}</span>
                        <span>{{ __('module-promo-codes::messages.used') }}: {{ number_format($promoCode->activations_count, 0, '.', ' ') }}</span>
                    </div>
                    <div class="content-row-meta">
                        @foreach($promoCode->rewards as $reward)
                            <span>
                                @if($iconUrls[$promoCode->id][$reward->item_id] ?? null)
                                    <img src="{{ $iconUrls[$promoCode->id][$reward->item_id] }}" alt="" width="24" height="24">
                                @endif
                                {{ $reward->displayName($promoCode->gameServer) }} × {{ number_format($reward->amount, 0, '.', ' ') }} <small>ID {{ $reward->item_id }}</small>
                            </span>
                        @endforeach
                    </div>
                </div>
                <div class="content-row-publication">
                    <span @class(['publication-state', $promoCode->availabilityCode() === 'active' ? 'published' : 'draft'])>{{ $promoCode->availabilityLabel() }}</span>
                </div>
                <div class="admin-row-actions content-row-actions">
                    <a wire:navigate class="button button-primary" href="{{ route('admin.module-pages.promo-codes.edit', ['adminPath' => $adminPath, 'promoCode' => $promoCode]) }}">{{ $canManage ? __('module-promo-codes::messages.edit') : __('module-promo-codes::messages.view') }}</a>
                    @if($canManage)
                        <form method="POST" action="{{ route('admin.module-pages.promo-codes.toggle', ['adminPath' => $adminPath, 'promoCode' => $promoCode]) }}">
                            @csrf
                            @method('PATCH')
                            <button class="button button-secondary" type="submit">{{ $promoCode->enabled ? __('module-promo-codes::messages.disable') : __('module-promo-codes::messages.enable') }}</button>
                        </form>
                        <form
                            method="POST"
                            action="{{ route('admin.module-pages.promo-codes.destroy', ['adminPath' => $adminPath, 'promoCode' => $promoCode]) }}"
                            data-promo-delete-form
                            data-confirm-message="{{ __('module-promo-codes::messages.delete_confirm', ['code' => $promoCode->code]) }}"
                        >
                            @csrf
                            @method('DELETE')
                            <button class="button button-danger" type="submit">{{ __('module-promo-codes::messages.delete') }}</button>
                        </form>
                    @endif
                </div>
            </article>
        @endforeach
    </div>

    @if($promoCodes->hasPages())
        <nav class="simple-pagination" aria-label="{{ __('module-promo-codes::messages.pagination') }}">
            <a wire:navigate @class(['button button-secondary', 'disabled' => $promoCodes->onFirstPage()]) href="{{ $promoCodes->previousPageUrl() ?? '#' }}">← {{ __('module-promo-codes::messages.previous') }}</a>
            <span>{{ __('module-promo-codes::messages.page_of', ['current' => $promoCodes->currentPage(), 'last' => $promoCodes->lastPage()]) }}</span>
            <a wire:navigate @class(['button button-secondary', 'disabled' => ! $promoCodes->hasMorePages()]) href="{{ $promoCodes->nextPageUrl() ?? '#' }}">{{ __('module-promo-codes::messages.next') }} →</a>
        </nav>
    @endif
@endif
@endsection
@push('scripts')
<script src="{{ asset('assets/admin/js/promo-codes.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
