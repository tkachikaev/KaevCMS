@extends('admin.layouts.panel')
@section('title', __('module-referrals::messages.server_title', ['server' => $server->nameFor()]))
@section('description', __('module-referrals::messages.server_help'))
@section('content')
@php($adminPath = request()->route('adminPath'))
@php($audience = old('audience', request()->query('audience')) === 'invitee' ? 'invitee' : 'inviter')
@include('module-referrals::admin.navigation')
<p><a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.referrals.index', ['adminPath' => $adminPath]) }}">{{ __('module-referrals::messages.back') }}</a></p>
@if(session('status'))<div class="notice notice-success" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice notice-error" role="alert"><strong>{{ __('module-referrals::messages.validation_error') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<section class="form-card">
    <h2>{{ __('module-referrals::messages.server_settings_title') }}</h2>
    @php($databaseReady = in_array($server->database_status, ['connected', 'configured'], true))
    <p><span @class(['status-badge', 'status-badge-success' => $databaseReady && $gatewaySupported])>{{ ! $databaseReady ? __('module-referrals::messages.server_not_confirmed') : ($gatewaySupported ? __('module-referrals::messages.database_supported') : __('module-referrals::messages.gateway_not_supported')) }}</span></p>
    <form class="referrals-settings-form" method="POST" action="{{ route('admin.module-pages.referrals.servers.update', ['adminPath' => $adminPath, 'server' => $server->id]) }}">
        @csrf @method('PUT')
        <x-admin.toggle id="referrals_server_enabled" name="enabled" :label="__('module-referrals::messages.server_enabled')" :checked="(bool) old('enabled', $settings->enabled)" :disabled="! $canManage" />
        <x-admin.toggle id="referrals_checks_paused" name="checks_paused" :label="__('module-referrals::messages.checks_paused')" :hint="__('module-referrals::messages.pause_help')" :checked="(bool) old('checks_paused', $settings->checks_paused)" :disabled="! $canManage" />
        <x-admin.toggle id="referrals_inviter_enabled" name="inviter_rewards_enabled" :label="__('module-referrals::messages.inviter_rewards_enabled')" :checked="(bool) old('inviter_rewards_enabled', $settings->inviter_rewards_enabled)" :disabled="! $canManage" />
        <x-admin.toggle id="referrals_invitee_enabled" name="invitee_rewards_enabled" :label="__('module-referrals::messages.invitee_rewards_enabled')" :checked="(bool) old('invitee_rewards_enabled', $settings->invitee_rewards_enabled)" :disabled="! $canManage" />
        <div class="admin-field">
            <label class="admin-field-label" for="max_rewarded_invitees">{{ __('module-referrals::messages.max_invitees') }}</label>
            <p class="form-help">{{ __('module-referrals::messages.max_invitees_help') }}</p>
            <input id="max_rewarded_invitees" type="number" name="max_rewarded_invitees" min="1" max="1000000" value="{{ old('max_rewarded_invitees', $settings->max_rewarded_invitees) }}" @disabled(! $canManage)>
        </div>
        @if($canManage)<div class="referrals-actions"><button type="submit" class="button button-primary">{{ __('module-referrals::messages.save') }}</button></div>@else<p>{{ __('Read-only mode') }}</p>@endif
    </form>
</section>
<section class="form-card">
    <h2>{{ __('module-referrals::messages.rules_title') }}</h2>
    <div class="admin-tabs" role="tablist" aria-label="{{ __('module-referrals::messages.rules_title') }}" data-referrals-audience-tabs>
        @foreach(['inviter','invitee'] as $side)
            <button type="button" id="referral-tab-{{ $side }}" role="tab" @class(['admin-tab', 'active' => $audience === $side]) aria-selected="{{ $audience === $side ? 'true' : 'false' }}" aria-controls="referral-panel-{{ $side }}" tabindex="{{ $audience === $side ? '0' : '-1' }}" data-referral-audience="{{ $side }}">{{ __('module-referrals::messages.'.$side) }}</button>
        @endforeach
    </div>
    @foreach(['inviter','invitee'] as $panelAudience)
    <div id="referral-panel-{{ $panelAudience }}" class="referrals-audience-panel" role="tabpanel" aria-labelledby="referral-tab-{{ $panelAudience }}" @if($audience !== $panelAudience) hidden @endif>
    @php($currentRules = $rules->where('audience', $panelAudience))
    <div class="admin-card-list content-list" data-referrals-rules>
        @forelse($currentRules as $rule)
            @include('module-referrals::admin.rule-row')
        @empty
            <p data-referrals-empty>{{ __('module-referrals::messages.no_rules') }}</p>
        @endforelse
    </div>
    @if($canManage)
        <h3>{{ __('module-referrals::messages.add_level') }}</h3>
        <form method="POST" class="referrals-settings-form" data-referrals-add-rule data-request-failed="{{ __('module-referrals::messages.save_failed') }}" action="{{ route('admin.module-pages.referrals.rules.store', ['adminPath' => $adminPath, 'server' => $server->id]) }}">
            @csrf
            <input type="hidden" name="audience" value="{{ $panelAudience }}">
            <div class="settings-grid referrals-rule-fields">
                @foreach(['level' => 65535, 'item_id' => 9223372036854775807, 'amount' => 9223372036854775807] as $field => $max)
                    <div class="admin-field">
                        <label class="admin-field-label" for="ref_{{ $panelAudience }}_{{ $field }}">{{ __('module-referrals::messages.'.$field) }}</label>
                        <input type="number" name="{{ $field }}" id="ref_{{ $panelAudience }}_{{ $field }}" min="1" max="{{ $max }}" value="{{ old('audience') === $panelAudience ? old($field) : '' }}" required>
                    </div>
                @endforeach
            </div>
            <p class="form-help">{{ __('module-referrals::messages.unknown_item_help') }}</p>
            <div class="referrals-actions"><x-admin.button type="submit" variant="primary">{{ __('module-referrals::messages.add_level') }}</x-admin.button></div>
            <div class="notice notice-error" role="alert" data-referrals-form-error hidden></div>
            <div class="notice notice-success" role="status" data-referrals-form-status hidden></div>
        </form>
    @endif
    </div>
    @endforeach
</section>
@endsection
@push('scripts')
<script src="{{ asset('assets/modules/referrals-admin.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
