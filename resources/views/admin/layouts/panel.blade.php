@extends('admin.layouts.app')

@section('body')
<div class="admin-shell">
    @persist('admin-sidebar')
        <aside class="admin-sidebar" data-admin-sidebar wire:navigate:scroll>
            <a wire:navigate.hover class="admin-brand" href="{{ route('admin.dashboard') }}">
                <span class="admin-brand-mark">L2</span>
                <span>
                    <strong>{{ config('app.name') }}</strong>
                    <small>{{ __('Control panel') }}</small>
                </span>
            </a>

            @include('admin.partials.navigation')

            <div class="admin-sidebar-footer">
                <a href="{{ public_route('home') }}" target="_blank" rel="noopener">{{ __('Open website') }} <span aria-hidden="true">↗</span></a>
                <span>{{ __('Version :version', ['version' => cms_version()]) }}</span>
            </div>
        </aside>
    @endpersist

    <main class="admin-main">
        <header class="admin-header">
            <div>
                <h1>@yield('title', __('Control panel'))</h1>
                @hasSection('description')
                    <p>@yield('description')</p>
                @endif
            </div>

            <div class="admin-user">
                @include('admin.partials.language-switcher')
                <details class="admin-account-menu">
                    <summary>
                        <span class="admin-account-avatar" aria-hidden="true"><span>{{ mb_strtoupper(mb_substr(auth('admin')->user()->name, 0, 1)) }}</span></span>
                        <span class="admin-account-copy">
                            <strong>{{ auth('admin')->user()->name }}</strong>
                            <small>{{ auth('admin')->user()->email }}</small>
                            <span class="admin-account-role">{{ auth('admin')->user()->roleLabel() }}</span>
                        </span>
                        <span class="admin-account-chevron" aria-hidden="true">⌄</span>
                    </summary>
                    <div class="admin-account-dropdown">
                        <a wire:navigate href="{{ route('admin.administrators.edit', auth('admin')->user()) }}">{{ __('My profile') }}</a>
                        <a wire:navigate href="{{ route('admin.account.security') }}">
                            {{ __('Account security') }}
                            <span @class(['account-menu-state', 'enabled' => auth('admin')->user()->twoFactorEnabled()])>
                                {{ auth('admin')->user()->twoFactorEnabled() ? __('2FA enabled') : __('2FA disabled') }}
                            </span>
                        </a>
                        <form method="POST" action="{{ route('admin.logout') }}">
                            @csrf
                            <button type="submit">{{ __('Sign out') }}</button>
                        </form>
                    </div>
                </details>
            </div>
        </header>

        @if (session('status'))
            <div class="notice notice-success" role="status">{{ session('status') }}</div>
        @endif

        @if (session('warning'))
            <div class="notice notice-warning" role="alert">{{ session('warning') }}</div>
        @endif

        @if ($errors->any())
            <div class="notice notice-error" role="alert">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <section class="admin-content">
            @if(request()->attributes->get('admin_read_only'))
                <div class="notice notice-info admin-read-only-notice" role="status">
                    <strong>{{ __('Read-only mode') }}</strong>
                    <span>{{ __('Your role allows viewing this section, but changes are unavailable.') }}</span>
                </div>
                <fieldset class="admin-read-only-fieldset" disabled>
                    @yield('content')
                </fieldset>
            @else
                @yield('content')
            @endif
        </section>
    </main>
</div>
@endsection
