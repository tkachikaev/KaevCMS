@extends('admin.layouts.panel')
@section('title', __('module-voting::messages.rewards'))
@section('description', __('module-voting::messages.reward_help'))
@section('docs', 'modules')
@section('content')
@php
    $adminPath = request()->route('adminPath');
@endphp
@include('module-voting::admin.navigation')
@if($server === null)
    <x-admin.card><div class="admin-empty-state empty-state"><p>{{ __('module-voting::messages.no_servers') }}</p></div></x-admin.card>
@else
    <x-admin.tabs :label="__('Game servers')" class="settings-section-tabs">
        @foreach($servers as $choice)
            <x-admin.tab wire:navigate :href="route('admin.module-pages.voting.rewards.index', ['adminPath' => $adminPath, 'server' => $choice->id])" :active="$choice->id === $server->id">{{ $choice->nameFor() }}</x-admin.tab>
        @endforeach
    </x-admin.tabs>
    @php
        $canManage = ! request()->attributes->get('admin_read_only');
        $oldRows = old('rewards', $items);
        $rows = is_array($oldRows) ? array_slice(array_values($oldRows), 0, 100) : $items;
        if ($rows === []) {
            $rows = [['item_id' => '', 'amount' => '']];
        }
        $previewUrl = route('admin.module-pages.voting.rewards.preview', ['adminPath' => $adminPath, 'server' => '__SERVER__', 'item' => '__ITEM__']);
    @endphp
    <x-admin.card>
        <x-admin.card-heading :title="$server->nameFor()" :description="__('module-voting::messages.reward_help')" />
        <form method="POST" class="settings-form" action="{{ route('admin.module-pages.voting.rewards.update', ['adminPath' => $adminPath, 'server' => $server->id]) }}">
            @csrf
            @method('PUT')
            <input id="game_server_id" type="hidden" value="{{ $server->id }}">
            <x-admin.toggle id="voting_rewards_enabled" name="enabled" :label="__('module-voting::messages.enabled')" :checked="(bool) old('enabled', $reward?->enabled ?? false)" :disabled="! $canManage" />
            <div data-promo-rewards-editor data-max-rows="100" data-limit-message="{{ __('module-voting::messages.reward_limit') }}" data-preview-url="{{ $previewUrl }}" data-unknown-item="{{ __('module-voting::messages.enter_item') }}" data-enter-item="{{ __('module-voting::messages.enter_item') }}">
                <div class="promo-reward-list" data-promo-reward-list>
                    @foreach($rows as $index => $row)
                        @php
                            $itemId = is_array($row) && is_scalar($row['item_id'] ?? null) ? $row['item_id'] : '';
                            $amount = is_array($row) && is_scalar($row['amount'] ?? null) ? $row['amount'] : '';
                            $validId = filter_var($itemId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
                            $preview = $validId !== false ? $catalog->findForServer($server, $validId) : null;
                        @endphp
                        @include('module-voting::admin.reward-row', ['index' => $index, 'itemId' => $itemId, 'amount' => $amount, 'preview' => $preview])
                    @endforeach
                </div>
                @if($canManage)
                    <div class="promo-reward-actions">
                        <button class="button button-secondary" type="button" data-promo-reward-add>+ {{ __('module-voting::messages.add_item') }}</button>
                        <small data-promo-reward-message>{{ __('module-voting::messages.reward_limit') }}</small>
                    </div>
                    <template data-promo-reward-template>
                        @include('module-voting::admin.reward-row', ['index' => null, 'itemId' => '', 'amount' => '', 'preview' => null])
                    </template>
                @endif
            </div>
            @if($canManage)
                <div class="form-actions"><button class="button button-primary" type="submit">{{ __('module-voting::messages.save') }}</button></div>
            @endif
        </form>
    </x-admin.card>
@endif
@endsection
@push('scripts')
<script src="{{ asset('assets/admin/js/promo-codes.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
