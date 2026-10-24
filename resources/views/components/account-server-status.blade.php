@inject('serverStatuses', \App\Services\Servers\ServerStatusOverview::class)
@php
    $statusServers = $serverStatuses->get(app()->getLocale())['game_servers'] ?? [];
@endphp
@if ($statusServers !== [])
    <div {{ $attributes->merge(['class' => 'account-server-status']) }} data-account-server-status>
        @foreach ($statusServers as $statusServer)
            @php
                $statusLabel = match ($statusServer['availability_state']) {
                    'online' => __('Available'),
                    'maintenance' => __('Maintenance'),
                    'offline' => __('Unavailable'),
                    default => __('Status pending'),
                };
            @endphp
            <div class="account-server-status-row">
                <strong>{{ $statusServer['name'] }}</strong>
                <span @class(['account-server-state', $statusServer['availability_state']])><i aria-hidden="true"></i>{{ $statusLabel }}</span>
                @if ($statusServer['public_players'] !== null)
                    <small>{{ __('Online') }}: {{ format_number($statusServer['public_players']) }}</small>
                @endif
            </div>
        @endforeach
    </div>
@endif
