@extends('admin.layouts.panel')

@section('title', __('Game server features'))
@section('description', __('Configure optional player services separately for each GameServer.'))

@section('content')
    <div class="notice notice-info game-feature-intro">
        <p>{{ __('Connections remain in Game servers. This section contains optional player actions such as character rescue and future character services.') }}</p>
    </div>

    @if($servers->isEmpty())
        <section class="form-card settings-narrow-card">
            <div class="settings-card-heading"><div><h2>{{ __('No game servers added') }}</h2><p>{{ __('Add a GameServer before configuring optional features.') }}</p></div></div>
            <a wire:navigate class="button button-primary" href="{{ route('admin.settings.game-server') }}">{{ __('Game servers') }}</a>
        </section>
    @else
        <div class="admin-card-list game-feature-list">
            <div class="admin-card-list-header game-feature-row game-feature-row-header">
                <span>{{ __('Game server') }}</span>
                <span>{{ __('Driver') }}</span>
                <span>{{ __('Character rescue') }}</span>
                <span>{{ __('Actions') }}</span>
            </div>
            @foreach($servers as $server)
                @php($enabled = in_array((int) $server->id, $enabledServerIds, true))
                <article class="admin-card-row game-feature-row">
                    <div class="game-feature-server"><strong>{{ $server->nameFor() }}</strong><span>{{ $server->chronicle ?: '—' }} @if($server->rates)· {{ $server->rates }}@endif</span></div>
                    <span>{{ $server->driver ?: '—' }}</span>
                    <span @class(['status-badge', 'status-badge-success' => $enabled, 'status-badge-muted' => ! $enabled])>{{ $enabled ? __('Enabled') : __('Disabled') }}</span>
                    <div class="admin-row-actions"><a wire:navigate class="button button-secondary" href="{{ route('admin.settings.game-server-features.edit', $server) }}">{{ __('Configure') }}</a></div>
                </article>
            @endforeach
        </div>
    @endif
@endsection
