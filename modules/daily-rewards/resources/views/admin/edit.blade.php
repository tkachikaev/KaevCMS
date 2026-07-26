@extends('admin.layouts.panel')
@inject('gameItemCatalog', 'App\Services\GameAssets\GameItemCatalog')
@section('title', __('module-daily-rewards::messages.edit_title', ['period' => $calendar->periodLabel()]))
@section('description', __('module-daily-rewards::messages.edit_description'))
@section('content')
@php($adminPath = request()->route('adminPath'))
@php($previewUrl = route('admin.module-pages.daily-rewards.items.preview', ['adminPath' => $adminPath, 'calendar' => $calendar, 'item' => '__ITEM__']))
<form
    method="POST"
    action="{{ route('admin.module-pages.daily-rewards.update', ['adminPath' => $adminPath, 'calendar' => $calendar]) }}"
    class="daily-reward-admin-editor"
    data-daily-reward-editor
    data-max-rows="100"
    data-preview-url="{{ $previewUrl }}"
    data-unknown-item="{{ __('module-daily-rewards::messages.unknown_item') }}"
    data-no-reward="{{ __('module-daily-rewards::messages.no_reward') }}"
    data-active-label="{{ __('module-daily-rewards::messages.active') }}"
    data-inactive-label="{{ __('module-daily-rewards::messages.inactive') }}"
    data-limit-message="{{ __('module-daily-rewards::messages.validation_max') }}"
    data-unsaved-confirm="{{ __('module-daily-rewards::messages.unsaved_confirm') }}"
    data-copy-empty-confirm="{{ __('module-daily-rewards::messages.copy_to_empty_confirm') }}"
>
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
                @error('enabled')<small class="form-error">{{ $message }}</small>@enderror
            </div>
        </div>
        <p class="form-help">{{ __('module-daily-rewards::messages.reward_editor_modal_help') }}</p>
    </section>

    <section class="form-card daily-reward-admin-calendar-shell">
        <div class="daily-reward-admin-calendar-heading">
            <div>
                <span class="admin-eyebrow">{{ __('module-daily-rewards::messages.calendar_days') }}</span>
                <h2>{{ __('module-daily-rewards::messages.calendar_preview_title') }}</h2>
                <p>{{ __('module-daily-rewards::messages.calendar_preview_help') }}</p>
            </div>
            @if($canManage)<span class="daily-reward-unsaved-status" data-daily-unsaved hidden>{{ __('module-daily-rewards::messages.unsaved_changes') }}</span>@endif
        </div>

        <div class="daily-reward-admin-weekdays" aria-hidden="true">
            @foreach(['monday','tuesday','wednesday','thursday','friday','saturday','sunday'] as $weekday)
                <span>{{ __('module-daily-rewards::messages.'.$weekday) }}</span>
            @endforeach
        </div>

        <div class="daily-reward-admin-calendar-grid">
            @for($blank = 0; $blank < $weekdayOffset; $blank++)
                <span class="daily-reward-admin-calendar-blank" aria-hidden="true"></span>
            @endfor

            @foreach($calendar->days as $day)
                @php($oldDay = old('days.'.$day->day_number))
                @php($rows = is_array($oldDay) ? ($oldDay['rewards'] ?? []) : $day->items->map(fn($item) => ['item_id' => (string) $item->item_id, 'amount' => (string) $item->amount])->all())
                @php($rows = is_array($rows) && $rows !== [] ? array_values($rows) : [['item_id' => '', 'amount' => '']])
                @php($dayEnabled = is_array($oldDay) ? (bool) ($oldDay['enabled'] ?? false) : $day->enabled)
                @php($locked = (int) ($day->claims_count ?? 0) > 0)
                @php($configuredRows = collect($rows)->filter(fn($row) => (int) ($row['item_id'] ?? 0) > 0))
                <button
                    type="button"
                    @class(['daily-reward-admin-calendar-day', 'is-enabled' => $dayEnabled, 'is-locked' => $locked, 'has-errors' => $errors->has('days.'.$day->day_number.'.*')])
                    data-daily-day-open="daily-reward-day-{{ $day->id }}"
                    data-daily-day-tile="{{ $day->day_number }}"
                    aria-haspopup="dialog"
                >
                    <span class="daily-reward-admin-calendar-day-head">
                        <strong>{{ $day->day_number }}</strong>
                        <small data-daily-tile-state>{{ $dayEnabled ? __('module-daily-rewards::messages.active') : __('module-daily-rewards::messages.inactive') }}</small>
                    </span>
                    <span class="daily-reward-admin-calendar-icons" data-daily-tile-icons>
                        @forelse($configuredRows->take(3) as $row)
                            @php($previewItemId = (int) ($row['item_id'] ?? 0))
                            <span class="daily-reward-admin-calendar-icon">
                                @if($iconUrls[$day->id][$previewItemId] ?? null)
                                    <img src="{{ $iconUrls[$day->id][$previewItemId] }}" alt="" width="38" height="38">
                                @else
                                    <i aria-hidden="true">◇</i>
                                @endif
                                <b>× {{ number_format((int) ($row['amount'] ?? 0), 0, '.', ' ') }}</b>
                            </span>
                        @empty
                            <span class="daily-reward-admin-calendar-empty">{{ __('module-daily-rewards::messages.no_reward_short') }}</span>
                        @endforelse
                        @if($configuredRows->count() > 3)<span class="daily-reward-admin-calendar-more">+{{ $configuredRows->count() - 3 }}</span>@endif
                    </span>
                    @if($locked)<span class="daily-reward-admin-calendar-lock" aria-label="{{ __('module-daily-rewards::messages.claimed_day_locked', ['day' => $day->day_number]) }}">🔒</span>@endif
                </button>
            @endforeach
        </div>
    </section>

    @foreach($calendar->days as $day)
        @php($oldDay = old('days.'.$day->day_number))
        @php($rows = is_array($oldDay) ? ($oldDay['rewards'] ?? []) : $day->items->map(fn($item) => ['item_id' => (string) $item->item_id, 'amount' => (string) $item->amount])->all())
        @php($rows = is_array($rows) && $rows !== [] ? array_values($rows) : [['item_id' => '', 'amount' => '']])
        @php($dayEnabled = is_array($oldDay) ? (bool) ($oldDay['enabled'] ?? false) : $day->enabled)
        @php($locked = (int) ($day->claims_count ?? 0) > 0)
        <dialog
            id="daily-reward-day-{{ $day->id }}"
            class="daily-reward-admin-dialog"
            data-daily-day
            data-day-number="{{ $day->day_number }}"
            @if($errors->has('days.'.$day->day_number.'.*')) data-daily-day-auto-open @endif
            aria-labelledby="daily-reward-day-title-{{ $day->id }}"
        >
            <div class="daily-reward-admin-dialog-card">
                <header class="daily-reward-admin-dialog-head">
                    <div>
                        <span class="admin-eyebrow">{{ $calendar->periodLabel() }}</span>
                        <h2 id="daily-reward-day-title-{{ $day->id }}">{{ __('module-daily-rewards::messages.day_number', ['day' => $day->day_number]) }}</h2>
                        <p>{{ $locked ? __('module-daily-rewards::messages.claimed_day_locked', ['day' => $day->day_number]) : __('module-daily-rewards::messages.day_dialog_help') }}</p>
                    </div>
                    <button type="button" class="daily-reward-admin-dialog-close" data-daily-day-close aria-label="{{ __('module-daily-rewards::messages.close') }}">×</button>
                </header>

                <div class="daily-reward-admin-dialog-body">
                    @if($locked)
                        <input type="hidden" name="days[{{ $day->day_number }}][enabled]" value="{{ $dayEnabled ? '1' : '0' }}">
                    @else
                        <input type="hidden" name="days[{{ $day->day_number }}][enabled]" value="0">
                    @endif
                    <label class="switch-row daily-reward-day-toggle">
                        <input @if(! $locked) name="days[{{ $day->day_number }}][enabled]" @endif type="checkbox" value="1" @checked($dayEnabled) @disabled(! $canManage || $locked) data-daily-day-enabled>
                        <span><strong>{{ __('module-daily-rewards::messages.day_enabled') }}</strong><small>{{ __('module-daily-rewards::messages.day_enabled_modal_help') }}</small></span>
                    </label>
                    @error('days.'.$day->day_number)<small class="form-error">{{ $message }}</small>@enderror
                    @error('days.'.$day->day_number.'.rewards')<small class="form-error">{{ $message }}</small>@enderror

                    <div class="daily-reward-item-list" data-daily-item-list>
                        @foreach($rows as $index => $row)
                            @php($previewItemId = (int) (is_array($row) ? ($row['item_id'] ?? 0) : 0))
                            <div class="daily-reward-item-row" data-daily-item-row>
                                <span class="daily-reward-item-preview" data-daily-item-preview aria-hidden="true">
                                    @if($previewItemId > 0 && ($iconUrls[$day->id][$previewItemId] ?? null))
                                        <img src="{{ $iconUrls[$day->id][$previewItemId] }}" alt="" width="48" height="48">
                                    @else
                                        <i>◇</i>
                                    @endif
                                </span>
                                <div class="form-group daily-reward-item-id-field">
                                    <label>{{ __('module-daily-rewards::messages.item_id') }}</label>
                                    @if($locked)<input type="hidden" name="days[{{ $day->day_number }}][rewards][{{ $index }}][item_id]" value="{{ is_array($row) ? ($row['item_id'] ?? '') : '' }}">@endif
                                    <input @if(! $locked) name="days[{{ $day->day_number }}][rewards][{{ $index }}][item_id]" @endif data-daily-item-id type="number" min="1" step="1" inputmode="numeric" value="{{ is_array($row) ? ($row['item_id'] ?? '') : '' }}" @disabled(! $canManage || $locked)>
                                    <small data-daily-item-name>{{ $previewItemId > 0 ? ($gameItemCatalog->knownName($calendar->gameServer, $previewItemId) ?? __('module-daily-rewards::messages.unknown_item')) : __('module-daily-rewards::messages.enter_item_id') }}</small>
                                    @error('days.'.$day->day_number.'.rewards.'.$index.'.item_id')<small class="form-error">{{ $message }}</small>@enderror
                                </div>
                                <div class="form-group">
                                    <label>{{ __('module-daily-rewards::messages.amount') }}</label>
                                    @if($locked)<input type="hidden" name="days[{{ $day->day_number }}][rewards][{{ $index }}][amount]" value="{{ is_array($row) ? ($row['amount'] ?? '') : '' }}">@endif
                                    <input @if(! $locked) name="days[{{ $day->day_number }}][rewards][{{ $index }}][amount]" @endif data-daily-item-amount type="number" min="1" step="1" inputmode="numeric" value="{{ is_array($row) ? ($row['amount'] ?? '') : '' }}" @disabled(! $canManage || $locked)>
                                    @error('days.'.$day->day_number.'.rewards.'.$index.'.amount')<small class="form-error">{{ $message }}</small>@enderror
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

                <footer class="daily-reward-admin-dialog-actions">
                    <button class="button button-primary" type="button" data-daily-day-close>{{ __('module-daily-rewards::messages.apply_day') }}</button>
                </footer>
            </div>
        </dialog>
    @endforeach

    @if($canManage)
        <template data-daily-item-template>
            <div class="daily-reward-item-row" data-daily-item-row>
                <span class="daily-reward-item-preview" data-daily-item-preview aria-hidden="true"><i>◇</i></span>
                <div class="form-group daily-reward-item-id-field"><label>{{ __('module-daily-rewards::messages.item_id') }}</label><input type="number" min="1" step="1" inputmode="numeric" data-daily-item-id><small data-daily-item-name>{{ __('module-daily-rewards::messages.enter_item_id') }}</small></div>
                <div class="form-group"><label>{{ __('module-daily-rewards::messages.amount') }}</label><input type="number" min="1" step="1" inputmode="numeric" data-daily-item-amount></div>
                <button class="button button-secondary daily-reward-item-remove" type="button" data-daily-item-remove aria-label="{{ __('module-daily-rewards::messages.remove_reward') }}">×</button>
            </div>
        </template>
    @endif

    <div class="admin-actions-panel editor-actions">
        @if($canManage)<span class="daily-reward-unsaved-status daily-reward-unsaved-status-footer" data-daily-unsaved hidden>{{ __('module-daily-rewards::messages.unsaved_changes') }}</span>@endif
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
