@extends('admin.layouts.panel')
@section('title', __('module-referrals::messages.admin_title'))
@section('content')
@php($adminPath = request()->route('adminPath'))
@php($databaseReady = in_array($server->database_status, ['connected', 'configured'], true))
@push('head')
    <link rel="stylesheet" href="{{ asset('assets/modules/referrals-admin.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush
<div class="referrals-editor" data-referrals-page
    data-referrals-preview-url="{{ route('admin.module-pages.referrals.items.preview', ['adminPath' => $adminPath, 'server' => $server->id, 'item' => 0]) }}"
    data-referrals-text-enter-item="{{ __('module-referrals::messages.enter_item_id') }}"
    data-referrals-text-unknown-item="{{ __('module-referrals::messages.unknown_item') }}">

    <div class="referrals-toolbar">
        <details class="referrals-server-picker">
            <summary class="button button-secondary">
                <span>{{ $server->nameFor() }}</span>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
            </summary>
            <div class="referrals-server-picker-menu" role="list">
                @foreach($servers as $choice)
                    @php($choiceSettings = $serverSettings->get($choice->id))
                    <a role="listitem" wire:navigate @class(['active' => $choice->id === $server->id]) @if($choice->id === $server->id) aria-current="page" @endif href="{{ route('admin.module-pages.referrals.servers.show', ['adminPath' => $adminPath, 'server' => $choice->id]) }}">
                        <span>{{ $choice->nameFor() }}</span>
                        <small @class(['is-on' => $choiceSettings?->enabled])>{{ $choiceSettings?->enabled ? __('module-referrals::messages.enabled') : __('module-referrals::messages.disabled') }}</small>
                    </a>
                @endforeach
            </div>
        </details>
        <span class="referrals-toolbar-spacer"></span>
        <a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.referrals.invites.index', ['adminPath' => $adminPath]) }}">{{ __('module-referrals::messages.invitation_journal') }}</a>
        <a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.referrals.grants.index', ['adminPath' => $adminPath]) }}">{{ __('module-referrals::messages.reward_journal') }}</a>
        <form method="POST" action="{{ route('admin.module-pages.referrals.settings.update', ['adminPath' => $adminPath]) }}" data-referrals-async @class(['referrals-switch-form', 'is-on' => $global->enabled])>
            @csrf @method('PUT')
            <input type="hidden" name="enabled" value="{{ $global->enabled ? '0' : '1' }}">
            <input type="hidden" name="invite_ttl_days" value="{{ $global->invite_ttl_days }}">
            <input type="hidden" name="_return_server" value="{{ $server->id }}">
            <span id="referrals-global-label">{{ $global->enabled ? __('module-referrals::messages.invitations_on') : __('module-referrals::messages.invitations_off') }}</span>
            <button type="submit" class="referrals-switch" role="switch" aria-checked="{{ $global->enabled ? 'true' : 'false' }}" aria-labelledby="referrals-global-label" @disabled(! $canManage)><span></span></button>
        </form>
    </div>

    @if(session('status'))<div class="notice notice-success referrals-notice" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="notice notice-error referrals-notice" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if(! $databaseReady || ! $gatewaySupported)
        <p class="notice notice-warning referrals-notice">{{ ! $databaseReady ? __('module-referrals::messages.server_not_confirmed') : __('module-referrals::messages.gateway_not_supported') }}</p>
    @endif

    <div class="referrals-layout">
        <aside class="referrals-side">
            <section class="referrals-card" aria-labelledby="referrals-server-title">
                <header class="referrals-card-head">
                    <h2 id="referrals-server-title">{{ __('module-referrals::messages.server_card_title') }}</h2>
                    <span class="admin-num referrals-card-meta">{{ __('module-referrals::messages.snapshot_count', ['count' => format_number($snapshotCount)]) }}</span>
                </header>
                <form class="referrals-settings-form" method="POST" action="{{ route('admin.module-pages.referrals.servers.update', ['adminPath' => $adminPath, 'server' => $server->id]) }}" data-referrals-async>
                    @csrf @method('PUT')
                    <x-admin.toggle compact id="referrals_server_enabled" name="enabled" :label="__('module-referrals::messages.server_enabled')" :checked="(bool) old('enabled', $settings->enabled)" :disabled="! $canManage" />
                    <x-admin.toggle compact id="referrals_inviter_enabled" name="inviter_rewards_enabled" :label="__('module-referrals::messages.inviter_rewards_enabled')" :checked="(bool) old('inviter_rewards_enabled', $settings->inviter_rewards_enabled)" :disabled="! $canManage" />
                    <x-admin.toggle compact id="referrals_invitee_enabled" name="invitee_rewards_enabled" :label="__('module-referrals::messages.invitee_rewards_enabled')" :checked="(bool) old('invitee_rewards_enabled', $settings->invitee_rewards_enabled)" :disabled="! $canManage" />
                    <x-admin.toggle compact id="referrals_checks_paused" name="checks_paused" :label="__('module-referrals::messages.checks_paused')" :hint="__('module-referrals::messages.pause_help_short')" :checked="(bool) old('checks_paused', $settings->checks_paused)" :disabled="! $canManage" />
                    <div class="referrals-inline-field">
                        <label for="max_rewarded_invitees">{{ __('module-referrals::messages.max_invitees') }}</label>
                        <input id="max_rewarded_invitees" type="number" name="max_rewarded_invitees" min="1" max="1000000" inputmode="numeric" placeholder="∞" value="{{ old('max_rewarded_invitees', $settings->max_rewarded_invitees) }}" @disabled(! $canManage) aria-describedby="max_rewarded_invitees_help">
                    </div>
                    <small id="max_rewarded_invitees_help" class="referrals-help">{{ __('module-referrals::messages.max_invitees_help_short') }}</small>
                    @if($canManage)<button type="submit" class="button button-primary referrals-save">{{ __('module-referrals::messages.save') }}</button>@endif
                </form>
            </section>

            <section class="referrals-card" aria-labelledby="referrals-general-title">
                <header class="referrals-card-head"><h2 id="referrals-general-title">{{ __('module-referrals::messages.general_settings') }}</h2></header>
                <form class="referrals-settings-form" method="POST" action="{{ route('admin.module-pages.referrals.settings.update', ['adminPath' => $adminPath]) }}" data-referrals-async>
                    @csrf @method('PUT')
                    <input type="hidden" name="enabled" value="{{ $global->enabled ? '1' : '0' }}">
                    <input type="hidden" name="_return_server" value="{{ $server->id }}">
                    <div class="referrals-inline-field">
                        <label for="invite_ttl_days">{{ __('module-referrals::messages.invitation_ttl_short') }}</label>
                        <input id="invite_ttl_days" name="invite_ttl_days" type="number" min="1" max="30" required inputmode="numeric" value="{{ old('invite_ttl_days', $global->invite_ttl_days) }}" @disabled(! $canManage)>
                    </div>
                    @if($canManage)<button type="submit" class="button button-secondary referrals-save">{{ __('module-referrals::messages.save') }}</button>@endif
                </form>
            </section>
        </aside>

        <section class="referrals-rules" aria-labelledby="referrals-rules-title">
            <header class="referrals-rules-head">
                <h2 id="referrals-rules-title">{{ __('module-referrals::messages.rules_title') }}</h2>
                <small>{{ __('module-referrals::messages.rules_hint') }}</small>
            </header>
            <div class="referrals-audiences">
                @foreach(['inviter', 'invitee'] as $panelAudience)
                    @php($currentRules = $rules->where('audience', $panelAudience))
                    @php($audienceEnabled = $panelAudience === 'inviter' ? $settings->inviter_rewards_enabled : $settings->invitee_rewards_enabled)
                    <div id="referral-panel-{{ $panelAudience }}" @class(['referrals-audience-panel', 'is-off' => ! $audienceEnabled]) aria-labelledby="referral-heading-{{ $panelAudience }}">
                        <div class="referrals-audience-head">
                            <h3 id="referral-heading-{{ $panelAudience }}">{{ __('module-referrals::messages.'.$panelAudience) }}</h3>
                            @unless($audienceEnabled)<span class="status-badge status-badge-muted">{{ __('module-referrals::messages.disabled') }}</span>@endunless
                            <span class="admin-num referrals-card-meta">{{ format_number($currentRules->count()) }} / 30</span>
                        </div>
                        <div class="referrals-rule-list" data-referrals-rules data-empty-text="{{ __('module-referrals::messages.no_rules') }}">
                            @forelse($currentRules as $rule)
                                @include('module-referrals::admin.rule-row')
                            @empty
                                <p class="referrals-empty" data-referrals-empty>{{ __('module-referrals::messages.no_rules') }}</p>
                            @endforelse
                        </div>
                        @if($canManage)
                            <form method="POST" class="referrals-add-rule" data-referrals-add-rule data-request-failed="{{ __('module-referrals::messages.save_failed') }}" action="{{ route('admin.module-pages.referrals.rules.store', ['adminPath' => $adminPath, 'server' => $server->id]) }}">
                                @csrf
                                <input type="hidden" name="audience" value="{{ $panelAudience }}">
                                <div class="referrals-add-grid">
                                    <div class="form-group referrals-level-field">
                                        <label for="ref_{{ $panelAudience }}_level">{{ __('module-referrals::messages.level') }}</label>
                                        <input type="number" name="level" id="ref_{{ $panelAudience }}_level" min="1" max="65535" inputmode="numeric" value="{{ old('audience') === $panelAudience ? old('level') : '' }}" required>
                                    </div>
                                    <span class="promo-reward-preview" data-referrals-item-preview aria-hidden="true"></span>
                                    <div class="form-group referrals-item-field">
                                        <label for="ref_{{ $panelAudience }}_item_id">{{ __('module-referrals::messages.item_id') }}</label>
                                        <input type="number" name="item_id" id="ref_{{ $panelAudience }}_item_id" min="1" max="9223372036854775807" inputmode="numeric" value="{{ old('audience') === $panelAudience ? old('item_id') : '' }}" required data-referrals-item-id>
                                        <small class="promo-reward-name-preview" data-referrals-item-name>{{ __('module-referrals::messages.enter_item_id') }}</small>
                                    </div>
                                    <div class="form-group referrals-amount-field">
                                        <label for="ref_{{ $panelAudience }}_amount">{{ __('module-referrals::messages.amount') }}</label>
                                        <input type="number" name="amount" id="ref_{{ $panelAudience }}_amount" min="1" max="9223372036854775807" inputmode="numeric" value="{{ old('audience') === $panelAudience ? old('amount') : '' }}" required>
                                    </div>
                                    <button type="submit" class="button button-primary referrals-add-button" aria-label="{{ __('module-referrals::messages.add_level') }}" title="{{ __('module-referrals::messages.add_level') }}">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                                    </button>
                                </div>
                                <div class="notice notice-error" role="alert" data-referrals-form-error hidden></div>
                                <div class="notice notice-success" role="status" data-referrals-form-status hidden></div>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    </div>
</div>
@endsection
@push('scripts')
<script src="{{ asset('assets/modules/referrals-admin.js') }}?v={{ cms_version() }}" defer data-navigate-track data-navigate-once></script>
@endpush
