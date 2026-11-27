@extends('admin.layouts.panel')
@section('title', 'KTreon — '.__('module-voting::messages.title'))
@section('description', __('module-voting::messages.provider_description'))
@section('docs', 'modules')
@section('content')
    @php($adminPath = request()->route('adminPath'))
    @include('module-voting::admin.navigation')
    @php($canManage = ! request()->attributes->get('admin_read_only'))
    <x-admin.card :narrow="true">
        <x-admin.card-heading :title="__('module-voting::messages.connection')" :description="__('module-voting::messages.setup_help')" />
        <form method="POST" class="settings-form" autocomplete="off" action="{{ route('admin.module-pages.voting.providers.ktreon.save', ['adminPath' => $adminPath]) }}">
            @csrf
            @method('PUT')
            <x-admin.toggle id="voting_enabled" name="enabled" :label="__('module-voting::messages.enabled')" :hint="__('module-voting::messages.enable_help')" :checked="(bool) old('enabled', $settings['enabled'])" :disabled="! $canManage" />
            <div class="admin-field">
                <label class="admin-field-label" for="voting_project">{{ __('module-voting::messages.project_id') }}</label>
                @php($projectValue = old('project_id', $settings['project_id']))
                <input id="voting_project" name="project_id" type="text" maxlength="36" autocomplete="off" spellcheck="false" value="{{ is_string($projectValue) ? $projectValue : $settings['project_id'] }}" @disabled(! $canManage)>
            </div>
            <div class="admin-field">
                <label class="admin-field-label" for="voting_key">{{ __('module-voting::messages.api_key') }}</label>
                <input id="voting_key" name="password" type="password" maxlength="47" autocomplete="new-password" value="" @disabled(! $canManage)>
                <small class="admin-field-help">{{ __('module-voting::messages.'.($settings['key_saved'] ? 'key_saved' : 'key_missing')) }}</small>
                <small class="admin-field-help">{{ __('module-voting::messages.key_help') }}</small>
            </div>
            <div class="admin-field">
                <label class="admin-field-label" for="voting_pending">{{ __('module-voting::messages.pending_policy') }}</label>
                <select id="voting_pending" name="pending_policy" @disabled(! $canManage)>
                    <option value="wait" @selected(old('pending_policy', $settings['pending_policy']) === 'wait')>{{ __('module-voting::messages.pending_wait') }}</option>
                    <option value="immediate" @selected(old('pending_policy', $settings['pending_policy']) === 'immediate')>{{ __('module-voting::messages.pending_immediate') }}</option>
                </select>
                <small class="admin-field-help">{{ __('module-voting::messages.pending_warning') }}</small>
            </div>
            @if($canManage)
                <div class="form-actions"><button type="submit" class="button button-primary">{{ __('module-voting::messages.save') }}</button></div>
            @endif
        </form>
    </x-admin.card>
    <x-admin.card :narrow="true">
        <x-admin.card-heading :title="__('module-voting::messages.check')" :description="__('module-voting::messages.check_help')" />
        <p>{{ __('module-voting::messages.last_check') }}: {{ $settings['last_checked_at']?->format('Y-m-d H:i:s') ?? '—' }}</p>
        @if($settings['last_check_result'])
            <p role="status">{{ __('module-voting::messages.check_'.$settings['last_check_result']) }}</p>
        @else
            <p>{{ __('module-voting::messages.not_checked') }}</p>
        @endif
        @if($canManage)
            <form method="POST" action="{{ route('admin.module-pages.voting.providers.ktreon.check', ['adminPath' => $adminPath]) }}">
                @csrf
                <button type="submit" class="button button-secondary">{{ __('module-voting::messages.check') }}</button>
            </form>
        @endif
    </x-admin.card>
    <x-admin.card :narrow="true">
        <x-admin.card-heading :title="__('module-voting::messages.player_access')" :description="__('module-voting::messages.access_help')" />
        <p>{{ __('module-voting::messages.'.($settings['player_access_enabled'] ? 'enabled' : 'disabled')) }}</p>
        @if($canManage)
            <form method="POST" class="settings-form" action="{{ route('admin.module-pages.voting.providers.ktreon.access', ['adminPath' => $adminPath]) }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="player_access_enabled" value="{{ $settings['player_access_enabled'] ? 0 : 1 }}">
                @unless($settings['player_access_enabled'])
                    <label><input type="checkbox" name="confirm" value="1" required> {{ __('module-voting::messages.access_confirm') }}</label>
                @endunless
                <button type="submit" class="button button-secondary">{{ __('module-voting::messages.'.($settings['player_access_enabled'] ? 'close_access' : 'open_access')) }}</button>
            </form>
        @endif
    </x-admin.card>
    <x-admin.card :narrow="true">
        <x-admin.card-heading :title="__('module-voting::messages.sync_title')" :description="__('module-voting::messages.sync_help')" />
        <p>{{ __('module-voting::messages.last_sync') }}: {{ $settings['last_sync_at']?->format('Y-m-d H:i:s') ?? '—' }}</p>
        @if($settings['last_sync_result'])
            <p role="status">{{ __('module-voting::messages.sync_'.$settings['last_sync_result']) }}</p>
        @endif
        @if($settings['sync_retry_at']?->isFuture())
            <p>{{ __('module-voting::messages.retry_at') }}: {{ $settings['sync_retry_at']->format('Y-m-d H:i:s') }}</p>
        @endif
    </x-admin.card>
@endsection
