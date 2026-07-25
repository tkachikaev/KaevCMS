@extends('admin.layouts.panel')
@inject('gameItemCatalog', 'App\Services\GameAssets\GameItemCatalog')
@section('title', __('module-daily-rewards::messages.edit_title', ['period' => $calendar->periodLabel()]))
@section('description', __('module-daily-rewards::messages.edit_description'))
@section('content')
@php($adminPath = request()->route('adminPath'))
@php($calendarLocked = (int) ($calendar->claims_count ?? 0) > 0)
<form method="POST" action="{{ route('admin.module-pages.daily-rewards.update', ['adminPath' => $adminPath, 'calendar' => $calendar]) }}" class="daily-reward-admin-editor" data-daily-reward-editor data-max-rows="100" data-limit-message="{{ __('module-daily-rewards::messages.validation_max') }}">
    @csrf
    @method('PUT')

    <div class="admin-overview content-toolbar">
        <div class="admin-overview-stat content-stat"><span>{{ __('module-daily-rewards::messages.game_server') }}</span><strong>{{ $calendar->gameServer->nameFor() }}</strong></div>
        <div class="admin-overview-stat content-stat"><span>{{ __('module-daily-rewards::messages.period') }}</span><strong>{{ $calendar->periodLabel() }}</strong></div>
        <p class="admin-overview-copy">{{ __('module-daily-rewards::messages.calendar_locked_hint') }}</p>
    </div>

    <section class="form-card daily-reward-settings-card">
        <div class="form-grid">
            <div class="form-group">
                <label>{{ __('module-daily-rewards::messages.state') }}</label>
                <input type="hidden" name="enabled" value="0">
                <label class="switch-row" for="calendar_enabled">
                    <input id="calendar_enabled" name="enabled" type="checkbox" value="1" @checked((bool) old('enabled', $calendar->enabled)) @disabled(! $canManage)>
                    <span><strong>{{ __('module-daily-rewards::messages.enabled_switch') }}</strong><small>{{ __('module-daily-rewards::messages.enabled_help') }}</small></span>
                </label>
            </div>
        </div>
        <p class="form-help">{{ __('module-daily-rewards::messages.reward_editor_help') }}</p>
    </section>

    <div class="daily-reward-admin-days">
        @foreach($calendar->days as $day)
            @php($oldDay = old('days.'.$day->day_number))
            @php($rows = is_array($oldDay) ? ($oldDay['rewards'] ?? []) : $day->items->map(fn($item) => ['item_id' => (string) $item->item_id, 'amount' => (string) $item->amount])->all())
            @php($rows = is_array($rows) && $rows !== [] ? array_values($rows) : [['item_id' => '', 'amount' => '']])
            @php($dayEnabled = is_array($oldDay) ? (bool) ($oldDay['enabled'] ?? false) : $day->enabled)
            @php($locked = (int) ($day->claims_count ?? 0) > 0)
            <details class="daily-reward-admin-day" data-daily-day data-day-number="{{ $day->day_number }}" @if($dayEnabled || $errors->has('days.'.$day->day_number.'*')) open @endif>
                <summary>
                    <span class="daily-reward-admin-day-number">{{ $day->day_number }}</span>
                    <span class="daily-reward-admin-day-summary">
                        <strong>{{ __('module-daily-rewards::messages.day_number', ['day' => $day->day_number]) }}</strong>
                        <small>{{ $day->items->isNotEmpty() ? $day->summary($calendar->gameServer) : __('module-daily-rewards::messages.no_reward') }}</small>
                    </span>
                    <span @class(['publication-state', $dayEnabled ? 'published' : 'draft'])>{{ $dayEnabled ? __('module-daily-rewards::messages.active') : __('module-daily-rewards::messages.inactive') }}</span>
                </summary>
                <div class="daily-reward-admin-day-body">
                    @if($locked)
                        <input type="hidden" name="days[{{ $day->day_number }}][enabled]" value="{{ $dayEnabled ? '1' : '0' }}">
                    @else
                        <input type="hidden" name="days[{{ $day->day_number }}][enabled]" value="0">
                    @endif
                    <label class="switch-row daily-reward-day-toggle">
                        <input @if(! $locked) name="days[{{ $day->day_number }}][enabled]" @endif type="checkbox" value="1" @checked($dayEnabled) @disabled(! $canManage || $locked) data-daily-day-enabled>
                        <span><strong>{{ __('module-daily-rewards::messages.day_enabled') }}</strong>@if($locked)<small>{{ __('module-daily-rewards::messages.claimed_day_locked', ['day' => $day->day_number]) }}</small>@endif</span>
                    </label>

                    <div class="daily-reward-item-list" data-daily-item-list>
                        @foreach($rows as $index => $row)
                            @php($previewItemId = (int) (is_array($row) ? ($row['item_id'] ?? 0) : 0))
                            <div class="daily-reward-item-row" data-daily-item-row>
                                <div class="form-group">
                                    <label>{{ __('module-daily-rewards::messages.item_id') }}</label>
                                    @if($locked)<input type="hidden" name="days[{{ $day->day_number }}][rewards][{{ $index }}][item_id]" value="{{ is_array($row) ? ($row['item_id'] ?? '') : '' }}">@endif
                                    <input @if(! $locked) name="days[{{ $day->day_number }}][rewards][{{ $index }}][item_id]" @endif data-daily-item-id type="number" min="1" step="1" inputmode="numeric" value="{{ is_array($row) ? ($row['item_id'] ?? '') : '' }}" @disabled(! $canManage || $locked)>
                                    @if($previewItemId > 0)
                                        <small>{{ $gameItemCatalog->knownName($calendar->gameServer, $previewItemId) ?? __('module-daily-rewards::messages.unknown_item') }}</small>
                                    @endif
                                </div>
                                <div class="form-group">
                                    <label>{{ __('module-daily-rewards::messages.amount') }}</label>
                                    @if($locked)<input type="hidden" name="days[{{ $day->day_number }}][rewards][{{ $index }}][amount]" value="{{ is_array($row) ? ($row['amount'] ?? '') : '' }}">@endif
                                    <input @if(! $locked) name="days[{{ $day->day_number }}][rewards][{{ $index }}][amount]" @endif data-daily-item-amount type="number" min="1" step="1" inputmode="numeric" value="{{ is_array($row) ? ($row['amount'] ?? '') : '' }}" @disabled(! $canManage || $locked)>
                                </div>
                                @if($canManage && ! $locked)
                                    <button class="button button-secondary daily-reward-item-remove" type="button" data-daily-item-remove aria-label="{{ __('module-daily-rewards::messages.remove_reward') }}">×</button>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @if($canManage && ! $locked)
                        <div class="daily-reward-day-actions">
                            <button class="button button-secondary" type="button" data-daily-item-add>+ {{ __('module-daily-rewards::messages.add_reward') }}</button>
                            @if($day->day_number > 1)<button class="button button-secondary" type="button" data-daily-copy-previous>{{ __('module-daily-rewards::messages.copy_previous') }}</button>@endif
                            <button class="button button-secondary" type="button" data-daily-copy-empty>{{ __('module-daily-rewards::messages.copy_to_empty') }}</button>
                        </div>
                    @endif
                </div>
            </details>
        @endforeach
    </div>

    @if($canManage)
        <template data-daily-item-template>
            <div class="daily-reward-item-row" data-daily-item-row>
                <div class="form-group"><label>{{ __('module-daily-rewards::messages.item_id') }}</label><input type="number" min="1" step="1" inputmode="numeric" data-daily-item-id></div>
                <div class="form-group"><label>{{ __('module-daily-rewards::messages.amount') }}</label><input type="number" min="1" step="1" inputmode="numeric" data-daily-item-amount></div>
                <button class="button button-secondary daily-reward-item-remove" type="button" data-daily-item-remove aria-label="{{ __('module-daily-rewards::messages.remove_reward') }}">×</button>
            </div>
        </template>
    @endif

    <div class="admin-actions-panel editor-actions">
        <a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.daily-rewards.index', ['adminPath' => $adminPath]) }}">{{ __('module-daily-rewards::messages.cancel') }}</a>
        @if($canManage)<button class="button button-primary" type="submit">{{ __('module-daily-rewards::messages.save') }}</button>@endif
    </div>
</form>
@endsection
@push('head')
<link rel="stylesheet" href="{{ asset('assets/modules/daily-rewards.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush
@push('scripts')
<script src="{{ asset('assets/admin/js/daily-rewards.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
