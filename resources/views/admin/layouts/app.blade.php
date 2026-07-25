<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $languageDirection ?? locale_direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="theme-color" content="#090c10">
    <title>@yield('title', __('Control panel')) — {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('assets/admin/css/app.css') }}?v={{ cms_version() }}" data-navigate-track>
    <script src="{{ asset('assets/admin/js/page-lifecycle.js') }}?v={{ cms_version() }}" defer data-navigate-track data-navigate-once></script>
    <script src="{{ asset('assets/admin/js/navigation.js') }}?v={{ cms_version() }}" defer data-navigate-track data-navigate-once></script>
    @livewireStyles
    @stack('head')
</head>
<body class="admin-body">
    @yield('body')
    @livewireScripts
    @stack('scripts')
</body>
</html>
