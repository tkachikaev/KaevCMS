@extends('admin.layouts.panel')
@section('title', __('module-referrals::messages.server_title', ['server' => $server->nameFor()]))
@section('description', __('module-referrals::messages.server_help'))
@section('content')
@php($adminPath = request()->route('adminPath'))
@php($audience = request()->query('audience') === 'invitee' ? 'invitee' : 'inviter')
<p><a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.referrals.index', ['adminPath' => $adminPath]) }}">{{ __('module-referrals::messages.back') }}</a></p>
@if(session('status'))<div class="notice notice-success" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice notice-error" role="alert"><strong>{{ __('module-referrals::messages.validation_error') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<section class="form-card">
    <h2>{{ __('module-referrals::messages.server_settings_title') }}</h2>
    <p class="form-help">{{ __('module-referrals::messages.snapshot_notice') }}</p>
    <p class="form-help">{{ __('module-referrals::messages.existing_snapshots', ['count' => format_number($snapshotCount)]) }}</p>
    @if(! $gatewaySupported)<p class="form-help">{{ __('module-referrals::messages.gateway_not_supported') }}</p>@endif
    <p class="form-help">{{ __('module-referrals::messages.server_status') }}: {{ $server->database_status === 'connected' ? __('module-referrals::messages.server_connected') : __('module-referrals::messages.server_not_confirmed') }}</p>
    <form class="settings-form" method="POST" action="{{ route('admin.module-pages.referrals.servers.update', ['adminPath' => $adminPath, 'server' => $server->id]) }}">
        @csrf @method('PUT')
        <x-admin.toggle id="referrals_server_enabled" name="enabled" :label="__('module-referrals::messages.server_enabled')" :checked="(bool) old('enabled', $settings->enabled)" :disabled="! $canManage" />
        <x-admin.toggle id="referrals_checks_paused" name="checks_paused" :label="__('module-referrals::messages.checks_paused')" :checked="(bool) old('checks_paused', $settings->checks_paused)" :disabled="! $canManage" />
        <x-admin.toggle id="referrals_inviter_enabled" name="inviter_rewards_enabled" :label="__('module-referrals::messages.inviter_rewards_enabled')" :checked="(bool) old('inviter_rewards_enabled', $settings->inviter_rewards_enabled)" :disabled="! $canManage" />
        <x-admin.toggle id="referrals_invitee_enabled" name="invitee_rewards_enabled" :label="__('module-referrals::messages.invitee_rewards_enabled')" :checked="(bool) old('invitee_rewards_enabled', $settings->invitee_rewards_enabled)" :disabled="! $canManage" />
        <div class="admin-field">
            <label class="admin-field-label" for="max_rewarded_invitees">{{ __('module-referrals::messages.max_invitees') }}</label>
            <p class="form-help">{{ __('module-referrals::messages.max_invitees_help') }}</p>
            <input id="max_rewarded_invitees" type="number" name="max_rewarded_invitees" min="1" max="1000000" value="{{ old('max_rewarded_invitees', $settings->max_rewarded_invitees) }}" @disabled(! $canManage)>
        </div>
        @if($canManage)<button type="submit" class="button button-primary">{{ __('module-referrals::messages.save') }}</button>@else<p>{{ __('Read-only mode') }}</p>@endif
    </form>
</section>
<section class="form-card">
    <h2>{{ __('module-referrals::messages.rules_title') }}</h2>
    <x-admin.tabs :label="__('module-referrals::messages.rules_title')" subtle>
        @foreach(['inviter','invitee'] as $side)
            <x-admin.tab wire:navigate :href="route('admin.module-pages.referrals.servers.show', ['adminPath' => $adminPath, 'server' => $server->id, 'audience' => $side])" :active="$audience === $side">{{ __('module-referrals::messages.'.$side) }}</x-admin.tab>
        @endforeach
    </x-admin.tabs>
    @php($currentRules = $rules->where('audience', $audience))
    <div class="admin-card-list content-list">
        @forelse($currentRules as $rule)
            <article class="admin-card-row content-row">
                <div class="content-row-preview">
                    @if($itemDetails[$rule->id]['icon'] ?? null)<img src="{{ $itemDetails[$rule->id]['icon'] }}" width="40" height="40" alt="">@endif
                </div>
                <div class="content-row-main">
                    <strong class="content-row-title">{{ __('module-referrals::messages.level') }} {{ format_number($rule->level) }}</strong>
                    <p>{{ $itemDetails[$rule->id]['name'] ?? __('module-referrals::messages.unknown_item') }}</p>
                    <div class="content-row-meta"><span>ID {{ format_number($rule->item_id) }}</span><span>× {{ format_number($rule->amount) }}</span></div>
                </div>
                @if($canManage)
                    <form method="POST" action="{{ route('admin.module-pages.referrals.rules.destroy', ['adminPath' => $adminPath, 'server' => $server->id, 'rule' => $rule->id]) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="button button-secondary">{{ __('module-referrals::messages.delete') }}</button>
                    </form>
                @endif
            </article>
        @empty
            <p>{{ __('module-referrals::messages.no_rules') }}</p>
        @endforelse
    </div>
    @if($canManage)
        <h3>{{ __('module-referrals::messages.add_level') }}</h3>
        <form method="POST" class="settings-form" action="{{ route('admin.module-pages.referrals.rules.store', ['adminPath' => $adminPath, 'server' => $server->id]) }}">
            @csrf
            <input type="hidden" name="audience" value="{{ $audience }}">
            <div class="settings-grid two-columns">
                @foreach(['level' => 65535, 'item_id' => 9223372036854775807, 'amount' => 9223372036854775807] as $field => $max)
                    <div class="admin-field">
                        <label class="admin-field-label" for="ref_{{ $field }}">{{ __('module-referrals::messages.'.$field) }}</label>
                        <input type="number" name="{{ $field }}" id="ref_{{ $field }}" min="1" max="{{ $max }}" value="{{ old($field) }}" required>
                    </div>
                @endforeach
            </div>
            <p class="form-help">{{ __('module-referrals::messages.unknown_item_help') }}</p>
            <button type="submit" class="button button-primary">{{ __('module-referrals::messages.add_level') }}</button>
        </form>
    @endif
</section>
@endsection
