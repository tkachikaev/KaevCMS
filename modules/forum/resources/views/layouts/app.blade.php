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
        .forum-tag{padding:2px 8px;background:#e8eef5;color:#405b79;border-radius:999px;font-size:11px}
        .forum-footer{margin:38px auto;color:#95a2b4;font-size:12px;text-align:center}
        @media(max-width:650px){.forum-top-inner{padding:14px 16px}.forum-body{padding:0 16px;margin:23px auto}.forum-heading h1{font-size:22px}}

        .forum-tools{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
        .forum-button-compact{padding:5px 9px;font-size:12px}
        .forum-button-primary{background:#355b85;color:#fff;border-color:#355b85}
        .forum-button-primary:hover{background:#294a70;color:#fff}
        .forum-button-danger{color:#9b3131;border-color:#edc4c4}
        .forum-notice{padding:12px 16px;border-radius:8px;background:#e6f4ec;color:#276044;margin:0 0 18px}
        .forum-notice-error{background:#fff0ed;color:#993b2f}
        .forum-notice-error p{margin:4px 0}
        .forum-category{margin-bottom:24px}
        .forum-category-heading{background:#e6ebf1;padding:13px 18px;border:1px solid #d8e0e9;border-radius:10px 10px 0 0;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
        .forum-category-heading h2{margin:0;font-size:17px}
        .forum-category-heading p{margin:3px 0 0;color:#74869a;font-size:12px}
        .forum-category-heading+.forum-panel{border-radius:0 0 10px 10px;border-top:0}
        .forum-section-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;border-bottom:1px solid #edf0f3}
        .forum-section-row:last-child{border-bottom:0}
        .forum-section-main{display:flex;align-items:center;gap:14px;min-width:0}
        .forum-section-icon{background:#eaf1f8;color:#42698f;border-radius:9px;min-width:41px;height:41px;text-align:center;line-height:41px;font-size:21px}
        .forum-section-title{font-size:15px;font-weight:670}
        .forum-section-main p{color:#74869a;margin:3px 0 0;font-size:12px}
        .forum-section-empty{font-size:12px;color:#97a4b3}
        .forum-actor-switch{margin-top:28px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;color:#657a91;font-size:13px}
        .forum-identity{display:flex;align-items:center;gap:7px}
        .forum-avatar{width:29px;height:29px;border-radius:50%;background:#e7edf5;display:inline-flex;align-items:center;justify-content:center;overflow:hidden}
        .forum-avatar img{width:100%;height:100%;object-fit:cover}
        .forum-breadcrumb{display:flex;gap:9px;align-items:center;margin:0 0 20px;font-size:13px;color:#8b9aad}
        .forum-trash-panel{margin-top:20px}
        .forum-dialog{background:#fff;color:#26394f;border:1px solid #dce4ec;border-radius:14px;box-shadow:0 18px 75px rgba(13,32,52,.23);padding:25px 26px;max-width:630px;width:calc(100% - 28px);max-height:calc(100vh - 30px);overflow:auto}
        .forum-dialog::backdrop{background:rgba(16,28,44,.52)}
        .forum-dialog-small{max-width:440px}
        .forum-dialog h2{font-size:20px;margin:0 0 18px}
        .forum-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
        .forum-form-grid label,.forum-field{display:flex;flex-direction:column;gap:6px;color:#50657e;font-size:13px}
        .forum-form-grid input,.forum-form-grid textarea,.forum-form-grid select,.forum-field select{width:100%;padding:9px 10px;border:1px solid #ced8e3;border-radius:8px;font:inherit;color:#26394f;background:#fff}
        .forum-form-grid input:focus,.forum-form-grid textarea:focus,.forum-form-grid select:focus{outline:2px solid #739cc4;outline-offset:1px}
        .forum-form-grid textarea{resize:vertical}
        .forum-permissions{margin-top:16px;grid-template-columns:repeat(3,minmax(0,1fr))}
        .forum-field{margin-top:14px}
        .forum-help{color:#7e8c9e;font-size:12px;margin:9px 0 0}
        .forum-confirm{display:flex;gap:9px;align-items:center;font-size:13px;color:#587089}
        .forum-dialog-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:22px}
        @media(max-width:650px){.forum-section-row{align-items:flex-start;flex-direction:column}.forum-form-grid,.forum-permissions{grid-template-columns:1fr}.forum-dialog{padding:18px}.forum-tools{gap:4px}}
    </style>
    <script src="{{ asset('assets/modules/forum/structure.js') }}?v={{ cms_version() }}" defer></script>
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
                <span class="forum-identity">@if($actor->user)<x-account-avatar :user="$actor->user" :fallback="$actor->user->publicName()" class="forum-avatar" />@endif {{ $actor->name() }}</span>
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
    @include('module-forum::partials.actor-switch')
</main>
<footer class="forum-footer">{{ site_name() }} · {{ __('module-forum::messages.title') }}</footer>
</body>
</html>
