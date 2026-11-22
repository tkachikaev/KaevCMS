@extends('admin.layouts.panel')

@section('title', __('module-forum::messages.themes_title'))
@section('description', __('module-forum::messages.themes_description'))

@section('content')
@php($adminPath = request()->route('adminPath'))
@php($canManage = auth('admin')->user()?->hasPermission(\App\Auth\AdminPermission::AppearanceManage))
<div class="admin-overview content-toolbar themes-toolbar">
    <div class="admin-overview-stat content-stat"><span>{{ __('module-forum::messages.themes_active') }}</span><strong>{{ $activeTheme['name'] }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('Installed') }}</span><strong>{{ count($themes) }}</strong></div>
    <div class="admin-overview-stat content-stat"><span>{{ __('Ready') }}</span><strong>{{ $readyCount }}</strong></div>
    {{-- A full page load: the forum is a separate page with its own styles. --}}
    <a class="button button-secondary" href="{{ route('modules.forum.index') }}" target="_blank" rel="noopener">{{ __('module-forum::messages.back_to_forum') }} ↗</a>
</div>

<div class="notice notice-info">
    <p>{!! __('module-forum::messages.themes_install_help') !!}</p>
</div>

@if ($errors->has('theme'))
    <div class="notice notice-error"><p>{{ $errors->first('theme') }}</p></div>
@endif

@if ($themes === [])
    <div class="admin-empty-state empty-box">{!! __('module-forum::messages.themes_empty') !!}</div>
@else
    <div class="admin-catalog-list themes-list" data-testid="forum-theme-catalog" data-layout="single-column">
        @foreach ($themes as $theme)
            <article data-testid="forum-theme-card" @class(['admin-card-row', 'admin-catalog-row', 'theme-card', 'theme-row', 'active' => $theme['active'], 'invalid' => ! $theme['valid'] || ! $theme['compatible']])>
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
                        <div class="admin-catalog-preview-placeholder"><span>{{ mb_strtoupper(mb_substr($theme['name'], 0, 1)) }}</span></div>
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
                            <a class="button button-secondary" href="{{ route('modules.forum.index') }}" target="_blank" rel="noopener">{{ __('View') }}</a>
                        @elseif ($theme['valid'] && $theme['compatible'] && $canManage)
                            <form method="POST" action="{{ route('admin.module-pages.forum.themes.activate', ['adminPath' => $adminPath, 'theme' => $theme['slug']]) }}">
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
