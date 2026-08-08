<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $languageDirection ?? locale_direction() }}" data-admin-appearance-key="kaevcms.admin.appearance.{{ auth('admin')->id() ?? 'guest' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="theme-color" content="#f3f6fa">
    <script src="{{ asset('assets/admin/js/appearance.js') }}?v={{ cms_version() }}" data-navigate-track data-navigate-once></script>
    <title>@yield('title', __('Control panel')) — {{ config('app.name') }}</title>
    @foreach ([
        'base',
        'layout',
        'content',
        'infrastructure',
        'components',
        'extensions',
        'catalogs',
        'appearance',
    ] as $adminStylesheet)
        <link rel="stylesheet" href="{{ asset('assets/admin/css/'.$adminStylesheet.'.css') }}?v={{ cms_version() }}" data-navigate-track>
    @endforeach
    <script src="{{ asset('assets/admin/js/page-lifecycle.js') }}?v={{ cms_version() }}" defer data-navigate-track data-navigate-once></script>
    <script src="{{ asset('assets/admin/js/clipboard.js') }}?v={{ cms_version() }}" defer data-navigate-track data-navigate-once></script>
    <script src="{{ asset('assets/admin/js/navigation.js') }}?v={{ cms_version() }}" defer data-navigate-track data-navigate-once></script>
    <script src="{{ asset('assets/admin/js/read-only.js') }}?v={{ cms_version() }}" defer data-navigate-track data-navigate-once></script>
    <script src="{{ asset('assets/admin/js/notifications.js') }}?v={{ cms_version() }}" defer data-navigate-track data-navigate-once></script>
    @livewireStyles
    @stack('head')
</head>
<body class="admin-body">
    @yield('body')
    @livewireScripts
    @stack('scripts')
</body>
</html>
