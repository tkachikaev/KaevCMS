@extends('admin.layouts.panel')
@section('title', __('module-referrals::messages.admin_title'))
@section('description', __('module-referrals::messages.admin_description'))
@section('content')
@php($adminPath = request()->route('adminPath'))
<div class="admin-overview content-toolbar">
    <div class="admin-overview-stat content-stat"><span>{{ __('module-referrals::messages.servers') }}</span><strong class="admin-num">{{ format_number($serverCount) }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('module-referrals::messages.configured_servers') }}</span><strong class="admin-num">{{ format_number($configuredServerCount) }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('module-referrals::messages.invites') }}</span><strong class="admin-num">{{ format_number($inviteCount) }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('module-referrals::messages.grants') }}</span><strong class="admin-num">{{ format_number($grantCount) }}</strong></div>
    <a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.referrals.invites.index', ['adminPath' => $adminPath]) }}">{{ __('module-referrals::messages.invitation_journal') }}</a>
    <a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.referrals.grants.index', ['adminPath' => $adminPath]) }}">{{ __('module-referrals::messages.reward_journal') }}</a>
</div>
@if(session('status'))<div class="notice notice-success" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice notice-error" role="alert"><strong>{{ __('module-referrals::messages.validation_error') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<section class="form-card">
    <h2>{{ __('module-referrals::messages.general_settings') }}</h2>
    <p class="form-help">{{ __('module-referrals::messages.general_settings_help') }}</p>
    <form class="settings-form" method="POST" action="{{ route('admin.module-pages.referrals.settings.update', ['adminPath' => $adminPath]) }}">
        @csrf @method('PUT')
        <x-admin.toggle id="referrals_global_enabled" name="enabled" :label="__('module-referrals::messages.accept_invitations')" :checked="(bool) old('enabled', $global->enabled)" :disabled="! $canManage" />
        <div class="admin-field">
            <label class="admin-field-label" for="invite_ttl_days">{{ __('module-referrals::messages.invitation_ttl') }}</label>
            <input id="invite_ttl_days" name="invite_ttl_days" type="number" min="1" max="30" required value="{{ old('invite_ttl_days', $global->invite_ttl_days) }}" @disabled(! $canManage)>
        </div>
        @if($canManage)<button type="submit" class="button button-primary">{{ __('module-referrals::messages.save') }}</button>@else<p>{{ __('Read-only mode') }}</p>@endif
    </form>
</section>
<section class="form-card">
    <h2>{{ __('module-referrals::messages.server_settings_title') }}</h2>
    <p class="form-help">{{ __('module-referrals::messages.snapshot_notice') }}</p>
    @if($servers->isEmpty())
        <div class="admin-empty-state empty-state"><h3>{{ __('module-referrals::messages.not_configured_title') }}</h3><p>{{ __('module-referrals::messages.no_servers') }}</p></div>
    @else
        @if($serverSettings->isEmpty())
            <div class="admin-empty-state empty-state"><h3>{{ __('module-referrals::messages.not_configured_title') }}</h3><p>{{ __('module-referrals::messages.not_configured_description') }}</p></div>
        @endif
        <div class="admin-card-list content-list">
            @foreach($servers as $server)
                @php($setting = $serverSettings->get($server->id))
                @php($count = (int) ($ruleCounts->get($server->id) ?? 0))
                <article class="admin-card-row content-row">
                    <div class="content-row-main">
                        <strong class="content-row-title">{{ $server->nameFor() }}</strong>
                        <div class="content-row-meta">
                            <span>{{ __('module-referrals::messages.rule_count', ['count' => format_number($count)]) }}</span>
                            <span>{{ $setting?->enabled ? __('module-referrals::messages.enabled') : __('module-referrals::messages.disabled') }}</span>
                            @if(! $gatewaySupport[$server->id])<span>{{ __('module-referrals::messages.gateway_not_supported') }}</span>@endif
                            @if($server->database_status !== 'connected')<span>{{ __('module-referrals::messages.server_not_confirmed') }}</span>@endif
                            @if($count === 0)<span>{{ __('module-referrals::messages.no_rules') }}</span>@endif
                        </div>
                    </div>
                    <div class="admin-row-actions content-row-actions"><a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.referrals.servers.show', ['adminPath' => $adminPath, 'server' => $server->id]) }}">{{ $canManage ? __('module-referrals::messages.configure') : __('module-referrals::messages.view') }}</a></div>
                </article>
            @endforeach
        </div>
    @endif
</section>
@endsection
