@extends('admin.layouts.panel')

@section('title', __('Themes'))
@section('description', __('Public website appearance. The control panel design does not depend on the active theme.'))

@section('content')
<div class="admin-overview content-toolbar themes-toolbar">
    <div class="admin-overview-stat content-stat"><span>{{ __('Active theme') }}</span><strong>{{ $activeThemeSlug ?: '—' }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('Installed') }}</span><strong>{{ count($themes) }}</strong></div>
    <a class="button button-secondary" href="{{ public_route('home') }}" target="_blank" rel="noopener">{{ __('Open website') }} ↗</a>
</div>

@if ($themes === [])
    <div class="admin-empty-state empty-box">{!! __('No themes found. Check the <code>themes</code> directory.') !!}</div>
@else
    <div class="themes-list" data-theme-list>
        @foreach ($themes as $theme)
            <article @class(['admin-card-row', 'theme-card', 'theme-row', 'active' => $theme['active'], 'invalid' => ! $theme['valid']])>
                <div class="theme-thumb">
                    @if ($theme['preview_url'])
                        <img src="{{ $theme['preview_url'] }}" alt="{{ __('Theme preview: :name', ['name' => $theme['name']]) }}">
                    @else
                        <span>{{ strtoupper(substr($theme['name'], 0, 1)) }}</span>
                    @endif
                </div>

                <div class="theme-info">
                    <div class="theme-heading">
                        <div><h2>{{ $theme['name'] }}</h2><p>{{ $theme['description'] ?: __('No theme description.') }}</p></div>
                        @if ($theme['active'])
                            <span class="theme-state active">{{ __('Active') }}</span>
                        @elseif ($theme['valid'])
                            <span class="theme-state ready">{{ __('Ready') }}</span>
                        @else
                            <span class="theme-state error">{{ __('Error') }}</span>
                        @endif
                    </div>

                    <div class="theme-meta-line">
                        <span>{{ __('Version :version', ['version' => $theme['version']]) }}</span>
                        <span>{{ __('Author: :author', ['author' => $theme['author']]) }}</span>
                        <span>{{ __('Directory: :slug', ['slug' => $theme['slug']]) }}</span>
                    </div>

                    @if (! $theme['valid'])
                        <div class="theme-errors"><p>{{ $theme['error'] }}</p></div>
                    @endif
                </div>

                <div class="theme-actions">
                    @if ($theme['active'])
                        <a class="button button-secondary" href="{{ public_route('home') }}" target="_blank" rel="noopener">{{ __('View') }}</a>
                    @elseif ($theme['valid'])
                        <form method="POST" action="{{ route('admin.themes.activate', ['theme' => $theme['slug']]) }}">
                            @csrf
                            <button class="button button-primary" type="submit">{{ __('Activate') }}</button>
                        </form>
                    @else
                        <button class="button button-secondary" type="button" disabled>{{ __('Unavailable') }}</button>
                    @endif
                </div>
            </article>
        @endforeach
    </div>
@endif
@endsection
