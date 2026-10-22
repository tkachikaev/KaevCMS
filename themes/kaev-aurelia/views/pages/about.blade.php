@extends('theme::layouts.app')
@section('scene', 'page')
@section('title'){{ (isset($page) && $page !== null ? $page->seoTitleFor() : __('About the server')).' — '.site_name() }}@endsection
@if (isset($page) && $page !== null && $page->seoDescriptionFor() !== '')
    @section('meta_description'){{ $page->seoDescriptionFor() }}@endsection
@endif

@section('content')
@php
    $page ??= null;
    $gameServers = game_servers();
@endphp
<section class="page-hero">
    <div class="container">
        <p class="eyebrow">{{ __('INFORMATION') }}</p>
        <h1>{{ $page !== null ? $page->titleFor() : __('About the server') }}</h1>
    </div>
</section>
<section class="container page-content">
    {{-- The text is the editable About page (Content → Pages); the cards come from the game server settings. --}}
    <div class="panel prose cms-page-prose">
        @if ($page !== null)
            {!! $page->safeBodyHtml() !!}
        @endif
        @if ($gameServers === [])
            @if ($page === null)
                <h2>{{ site_name() }}</h2>
                <p>{{ __('Game server information has not been added yet.') }}</p>
            @endif
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
        @endif
    </div>
</section>
@endsection
