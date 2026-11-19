<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ locale_direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('module-forum::messages.title')) — {{ site_name() }}</title>
    @if(site_favicon_url())
        <link rel="icon" href="{{ site_favicon_url() }}">
    @endif
    <style>
        @font-face{font-family:Inter;src:url("{{ asset('assets/admin/fonts/inter-variable.woff2') }}") format("woff2");font-display:swap}
        :root{font-family:Inter,system-ui,sans-serif;color:#27374b;background:#f3f5f8}
        *{box-sizing:border-box}
        body{margin:0;font-size:15px;line-height:1.5}
        a{color:#365b83;text-decoration:none}a:hover{text-decoration:underline}
        .forum-top{background:#fff;border-bottom:1px solid #dfe5ed}
        .forum-top-inner{max-width:1130px;margin:auto;min-height:76px;padding:12px 24px;display:flex;gap:22px;align-items:center;justify-content:space-between;flex-wrap:wrap}
        .forum-brand{display:flex;align-items:center;gap:12px;color:#27374b;font-size:22px;font-weight:720}
        .forum-brand img{max-width:180px;max-height:46px;object-fit:contain}
        .forum-actions{display:flex;align-items:center;flex-wrap:wrap;gap:17px;font-size:13px}
        .forum-body{max-width:1130px;margin:38px auto;padding:0 24px}
        .forum-heading{display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap}
        .forum-heading h1{font-size:26px;letter-spacing:-.025em;margin:0}
        .forum-desc{color:#697e96;margin:8px 0 26px}
        .forum-panel{border:1px solid #dfe5ed;border-radius:12px;background:#fff;box-shadow:0 5px 18px rgba(30,54,78,.035);overflow:hidden}
        .forum-panel-heading{background:#f4f7fb;border-bottom:1px solid #e2e8f0;padding:13px 20px;font-size:14px;font-weight:650}
        .forum-panel-body{padding:26px 20px;color:#667b92}
        .forum-button{display:inline-block;background:#f5f8fc;border:1px solid #d9e1eb;border-radius:8px;color:#304f71;padding:8px 12px;font:inherit;font-size:13px;cursor:pointer}
        .forum-button:hover{background:#edf3fa;text-decoration:none}
        .forum-actor{display:flex;gap:8px;align-items:center;flex-wrap:wrap;color:#566a7d;font-size:13px}
        .forum-tag{padding:2px 8px;background:#e8eef5;color:#405b79;border-radius:999px;font-size:11px}
        .forum-footer{margin:38px auto;color:#95a2b4;font-size:12px;text-align:center}
        @media(max-width:650px){.forum-top-inner{padding:14px 16px}.forum-body{padding:0 16px;margin:23px auto}.forum-heading h1{font-size:22px}}
    </style>
</head>
<body>
<header class="forum-top">
    <div class="forum-top-inner">
        <a class="forum-brand" href="{{ route('modules.forum.index') }}">
            @if(site_logo_url())
                <img src="{{ site_logo_url() }}" alt="{{ site_name() }}">
            @else
                <span>{{ site_name() }}</span>
            @endif
            <span>{{ __('module-forum::messages.title') }}</span>
        </a>
        <nav class="forum-actions" aria-label="{{ __('module-forum::messages.title') }}">
            <a href="{{ public_route('home') }}">{{ __('module-forum::messages.website') }}</a>
            @if($actor->isGuest())
                <a href="{{ public_route('login') }}">{{ __('module-forum::messages.login') }}</a>
            @else
                @if($actor->user)
                    <a href="{{ public_route('account') }}">{{ __('module-forum::messages.account') }}</a>
                @endif
                <span>{{ $actor->name() }}</span>
                @if($actor->label()) <span class="forum-tag">{{ $actor->label() }}</span> @endif
            @endif
            @if($actor->isOwner())
                <a class="forum-button" data-forum-owner-settings href="{{ route('admin.module-pages.forum.settings', ['adminPath' => app(\App\Services\Admin\AdminPathSettings::class)->path()]) }}">
                    {{ __('module-forum::messages.settings_link') }}
                </a>
            @endif
            <a href="{{ route('language.switch', ['locale' => 'ru']) }}">RU</a>
            <a href="{{ route('language.switch', ['locale' => 'en']) }}">EN</a>
        </nav>
    </div>
</header>
<main class="forum-body">
    @yield('content')
</main>
<footer class="forum-footer">{{ site_name() }} · {{ __('module-forum::messages.title') }}</footer>
</body>
</html>
