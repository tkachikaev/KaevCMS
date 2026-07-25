@extends('theme::layouts.app')
@section('title', __('About the server').' — '.site_name())

@section('content')
@php
    $gameServers = game_servers();
@endphp
<section class="page-hero">
    <div class="container">
        <p class="eyebrow">{{ __('INFORMATION') }}</p>
        <h1>{{ __('About the server') }}</h1>
    </div>
</section>
<section class="container page-content">
    <div class="panel prose">
        @if ($gameServers === [])
            <h2>{{ site_name() }}</h2>
            <p>{{ __('Game server information has not been added yet.') }}</p>
        @else
            <h2>{{ count($gameServers) > 1 ? __('Game servers') : $gameServers[0]['name'] }}</h2>
            <div class="about-server-list">
                @foreach ($gameServers as $gameServer)
                    <article class="about-server-card">
                        <h3>{{ $gameServer['name'] }}</h3>
                        @if ($gameServer['show_chronicle'] || $gameServer['show_rates'] || $gameServer['show_mode'])
                            <dl>
                                @if ($gameServer['show_chronicle'])
                                    <div><dt>{{ __('Chronicle') }}</dt><dd>{{ $gameServer['chronicle'] }}</dd></div>
                                @endif
                                @if ($gameServer['show_rates'])
                                    <div><dt>{{ __('Rates') }}</dt><dd>{{ $gameServer['rates'] }}</dd></div>
                                @endif
                                @if ($gameServer['show_mode'])
                                    <div><dt>{{ __('Mode') }}</dt><dd>{{ $gameServer['mode'] }}</dd></div>
                                @endif
                            </dl>
                        @endif
                    </article>
                @endforeach
            </div>
            <p>{{ __('This is the default information page of the theme. Its content will be editable in the CMS later.') }}</p>
        @endif
    </div>
</section>
@endsection
