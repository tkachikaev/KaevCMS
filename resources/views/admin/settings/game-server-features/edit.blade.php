@extends('admin.layouts.panel')

@section('title', __('Game server features'))
@section('description', $gameServer->nameFor().' — '.__('optional player services'))

@section('content')
    <div class="admin-page-back"><a wire:navigate href="{{ route('admin.settings.game-server-features.index') }}">← {{ __('All game servers') }}</a></div>

    <section class="form-card settings-narrow-card game-feature-capability-card">
        <div class="settings-card-heading">
            <div><h2>{{ $gameServer->nameFor() }}</h2><p>{{ $gameServer->chronicle ?: __('Chronicle not specified') }} @if($gameServer->rates)· {{ $gameServer->rates }}@endif</p></div>
            @if($capabilityError)
                <span class="status-badge status-badge-danger">{{ __('Database check failed') }}</span>
            @elseif($capabilities->supported)
                <span class="status-badge status-badge-success">{{ __('Character rescue supported') }}</span>
            @else
                <span class="status-badge status-badge-muted">{{ __('Character rescue unavailable') }}</span>
            @endif
        </div>
        @if($capabilityError)
            <p class="field-help">{{ __('The GameServer database could not be checked. Saved settings are preserved, but the player button will fail safely until the connection is restored.') }}</p>
        @elseif(! $capabilities->supported)
            <p class="field-help">{{ __('Required characters table columns are missing or the selected driver is unsupported.') }}</p>
            @if($capabilities->missingColumns !== [])<code class="game-feature-missing-columns">{{ implode(', ', $capabilities->missingColumns) }}</code>@endif
        @else
            <p class="field-help">{{ __('The driver confirmed offline status and coordinate columns required for a direct rescue.') }}</p>
        @endif
    </section>

    <form class="settings-form" method="POST" action="{{ route('admin.settings.game-server-features.update', $gameServer) }}">
        @csrf
        @method('PUT')
        <section class="form-card settings-narrow-card">
            <div class="settings-card-heading"><div><h2>{{ __('Return character to city') }}</h2><p>{{ __('Players will see the action in an offline character card. The operation updates only the saved coordinates.') }}</p></div></div>

            <label class="settings-toggle-row" for="enabled">
                <span><strong>{{ __('Enable character rescue') }}</strong><small>{{ __('The button is shown only for offline characters belonging to the signed-in user.') }}</small></span>
                <span class="switch-control"><input name="enabled" type="hidden" value="0"><input id="enabled" name="enabled" type="checkbox" value="1" @checked(old('enabled', $rescue['enabled']))><span aria-hidden="true"></span></span>
            </label>

            <label class="settings-field"><span>{{ __('Location name') }}</span><input type="text" name="location_name" maxlength="100" value="{{ old('location_name', $rescue['location_name']) }}" required><small>{{ __('Shown to the player in the confirmation window.') }}</small></label>

            <div class="settings-grid three-columns game-feature-coordinate-grid">
                <label class="settings-field"><span>{{ __('Coordinate X') }}</span><input type="number" name="x" value="{{ old('x', $rescue['x']) }}" required></label>
                <label class="settings-field"><span>{{ __('Coordinate Y') }}</span><input type="number" name="y" value="{{ old('y', $rescue['y']) }}" required></label>
                <label class="settings-field"><span>{{ __('Coordinate Z') }}</span><input type="number" name="z" value="{{ old('z', $rescue['z']) }}" required></label>
            </div>

            <div class="settings-grid two-columns">
                <label class="settings-field"><span>{{ __('Minimum offline time') }}</span><input type="number" name="offline_delay_minutes" min="0" max="1440" value="{{ old('offline_delay_minutes', $rescue['offline_delay_minutes']) }}" required><small>{{ __('Minutes after the last game session before rescue is allowed.') }}</small></label>
                <label class="settings-field"><span>{{ __('Reuse cooldown') }}</span><input type="number" name="cooldown_hours" min="0" max="720" value="{{ old('cooldown_hours', $rescue['cooldown_hours']) }}" required><small>{{ __('Hours between successful rescues of the same character. Use 0 to disable cooldown.') }}</small></label>
            </div>
        </section>
        <div class="admin-actions-panel settings-actions settings-actions-narrow"><button class="button button-primary" type="submit">{{ __('Save settings') }}</button></div>
    </form>
@endsection
