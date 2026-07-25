@extends('admin.layouts.panel')
@section('title', __('module-daily-rewards::messages.create_title'))
@section('description', __('module-daily-rewards::messages.create_description'))
@section('content')
@php($adminPath = request()->route('adminPath'))
<form method="POST" action="{{ route('admin.module-pages.daily-rewards.store', ['adminPath' => $adminPath]) }}" class="content-editor">
    @csrf
    <div class="editor-main">
        <section class="form-card">
            <div class="form-grid two-columns">
                <div class="form-group">
                    <label for="game_server_id">{{ __('module-daily-rewards::messages.game_server') }}</label>
                    <select id="game_server_id" name="game_server_id" required>
                        <option value="">{{ __('module-daily-rewards::messages.select_calendar') }}</option>
                        @foreach($gameServers as $server)
                            <option value="{{ $server->id }}" @selected((int) old('game_server_id') === $server->id)>{{ $server->nameFor() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label for="year">{{ __('module-daily-rewards::messages.year') }}</label>
                    <input id="year" name="year" type="number" min="2020" max="2100" value="{{ old('year', $calendar->year) }}" required>
                </div>
                <div class="form-group">
                    <label for="month">{{ __('module-daily-rewards::messages.month') }}</label>
                    <select id="month" name="month" required>
                        @foreach(range(1, 12) as $month)
                            <option value="{{ $month }}" @selected((int) old('month', $calendar->month) === $month)>{{ \Illuminate\Support\Carbon::create(2026, $month, 1)->locale(app()->getLocale())->translatedFormat('F') }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </section>
    </div>
    <aside class="editor-sidebar">
        <section class="form-card">
            <h2>{{ __('module-daily-rewards::messages.state') }}</h2>
            <input type="hidden" name="enabled" value="0">
            <span class="publication-state draft">{{ __('module-daily-rewards::messages.inactive') }}</span>
            <p class="form-help">{{ __('module-daily-rewards::messages.create_disabled_help') }}</p>
        </section>
    </aside>
    <div class="admin-actions-panel editor-actions">
        <a wire:navigate class="button button-secondary" href="{{ route('admin.module-pages.daily-rewards.index', ['adminPath' => $adminPath]) }}">{{ __('module-daily-rewards::messages.cancel') }}</a>
        <button class="button button-primary" type="submit">{{ __('module-daily-rewards::messages.create') }}</button>
    </div>
</form>
@endsection
