@extends('admin.layouts.panel')

@section('title', __('Player account themes'))
@section('description', __('The player account design is independent from the public website theme and from the administration panel.'))

@section('content')
<div class="admin-overview content-toolbar themes-toolbar">
    <div class="admin-overview-stat content-stat"><span>{{ __('Active account theme') }}</span><strong>{{ $activeThemeSlug ?: '—' }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('Installed') }}</span><strong>{{ count($themes) }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('Ready') }}</span><strong>{{ $validThemeCount }}</strong></div>
    <a class="button button-secondary" href="{{ public_route('account') }}" target="_blank" rel="noopener">{{ __('Open player account') }} ↗</a>
</div>

<div class="notice notice-info">
    <p>{!! __('To install a player account theme, copy its Blade package to <code>account-themes/&lt;slug&gt;</code> and public assets to <code>public/account-themes/&lt;slug&gt;</code>. The CMS validates the manifest and compatibility before activation.') !!}</p>
</div>

@if ($errors->has('theme'))
    <div class="notice notice-error"><p>{{ $errors->first('theme') }}</p></div>
@endif

@if ($themes === [])
    <div class="admin-empty-state empty-box">{!! __('No account themes found. Check the <code>account-themes</code> directory.') !!}</div>
@else
    <div class="admin-catalog-list themes-list" data-account-theme-list>
        @foreach ($themes as $theme)
            <article @class(['admin-card-row', 'admin-catalog-row', 'theme-card', 'theme-row', 'active' => $theme['active'], 'invalid' => ! $theme['valid'] || ! $theme['compatible']])>
                <div class="admin-catalog-main theme-info">
                    <div class="admin-catalog-heading theme-heading">
                        <h2>{{ $theme['name'] }}</h2>
                        <p>{{ $theme['description'] ?: __('No theme description.') }}</p>
                    </div>

                    <div class="admin-catalog-meta theme-meta-line">
                        <span>{{ __('Version :version', ['version' => $theme['version']]) }}</span>
                        <span>{{ __('Author: :author', ['author' => $theme['author']]) }}</span>
                        <span>{{ __('Directory: :slug', ['slug' => $theme['slug']]) }}</span>
                    </div>

                    @if ($theme['errors'] !== [])
                        <div class="theme-errors">
                            @foreach ($theme['errors'] as $error)<p>{{ $error }}</p>@endforeach
                        </div>
                    @endif
                </div>

                <div class="admin-catalog-preview theme-catalog-preview theme-thumb">
                    @if ($theme['preview_url'])
                        <img src="{{ $theme['preview_url'] }}" alt="{{ __('Theme preview: :name', ['name' => $theme['name']]) }}">
                    @else
                        <div class="admin-catalog-preview-placeholder"><span>{{ strtoupper(substr($theme['name'], 0, 1)) }}</span></div>
                    @endif
                </div>

                <div class="admin-catalog-side">
                    @if ($theme['active'])
                        <span class="theme-state active">{{ __('Active') }}</span>
                    @elseif ($theme['valid'] && $theme['compatible'])
                        <span class="theme-state ready">{{ __('Ready') }}</span>
                    @else
                        <span class="theme-state error">{{ __('Error') }}</span>
                    @endif

                    <div class="admin-row-actions admin-catalog-actions theme-actions">
                        @if ($theme['active'])
                            <a class="button button-secondary" href="{{ public_route('account') }}" target="_blank" rel="noopener">{{ __('View') }}</a>
                        @elseif ($theme['valid'] && $theme['compatible'])
                            <form method="POST" action="{{ route('admin.account-themes.activate', ['theme' => $theme['slug']]) }}">
                                @csrf
                                <button class="button button-primary" type="submit">{{ __('Activate') }}</button>
                            </form>
                        @else
                            <button class="button button-secondary" type="button" disabled>{{ __('Unavailable') }}</button>
                        @endif
                    </div>
                </div>
            </article>
        @endforeach
    </div>
@endif
@endsection
