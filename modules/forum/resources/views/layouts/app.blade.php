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

        .forum-topic-row{display:flex;justify-content:space-between;align-items:center;gap:18px;padding:18px 20px;border-bottom:1px solid #edf0f3}
        .forum-topic-row:last-child{border-bottom:0}
        .forum-topic-main{min-width:0}
        .forum-topic-title{font-weight:680;font-size:15px;overflow-wrap:anywhere}
        .forum-topic-meta{font-size:12px;color:#8493a5;margin:5px 0 0}
        .forum-topic-stats{text-align:right;color:#62758b;font-size:12px;flex-shrink:0}
        .forum-topic-stats small{display:block;color:#899aab;margin-top:5px;line-height:1.55}
        .forum-section-summary{display:flex;flex-direction:column;gap:3px;font-size:12px;color:#667b92;text-align:right}
        .forum-section-summary small{color:#96a2b1}
        .forum-compose{padding:20px;display:flex;flex-direction:column;gap:17px}
        .forum-compose-field{display:flex;flex-direction:column;gap:7px;color:#536982;font-size:13px}
        .forum-compose-field input,.forum-compose-field textarea{font:inherit;font-size:14px;color:#27374b;border:1px solid #ced8e3;border-radius:8px;background:#fff;padding:11px 12px;width:100%;min-width:0}
        .forum-compose-field textarea{resize:vertical;min-height:125px}
        .forum-compose-field input:focus,.forum-compose-field textarea:focus{outline:2px solid #739cc4;outline-offset:1px}
        .forum-pagination{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:5px;margin:20px 0}
        .forum-pagination a,.forum-pagination strong,.forum-page-disabled{padding:6px 10px;border-radius:7px;border:1px solid #dfe5ed;background:#fff;font-size:13px}
        .forum-pagination strong{background:#e7eef8;color:#365b83}
        .forum-page-disabled{color:#9caab8;background:transparent}
        .forum-post{padding:19px 22px;border-bottom:1px solid #edf0f3;scroll-margin-top:20px}
        .forum-post:last-child{border-bottom:0}
        .forum-post-header{display:flex;align-items:center;flex-wrap:wrap;gap:9px;margin-bottom:13px}
        .forum-post-author{color:#304f71;font-weight:700}
        .forum-post-date{margin-left:auto;color:#95a3b4;font-size:12px}
        .forum-post-edited{display:block;margin-top:6px;color:#8999ac;font-size:11px}
        .forum-post-removed{color:#8594a5;font-style:italic}
        .forum-post-tools{display:flex;align-items:center;flex-wrap:wrap;gap:7px;margin-top:12px}
        .forum-post-tools form{margin:0}
        .forum-post-body{white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word;color:#2d3a4c;line-height:1.65}
        .forum-reply-panel{margin-top:25px}
        .forum-read-only{background:#edf0f4;color:#788a9e;padding:12px 16px;border-radius:9px;font-size:13px;margin-top:24px}
        @media(max-width:650px){.forum-topic-row{flex-direction:column;align-items:flex-start}.forum-topic-stats{text-align:left}.forum-section-summary{text-align:left}.forum-post{padding:16px}.forum-post-date{margin-left:0}}

        /* Forum-only presentation for the shared Tiptap editor; no admin styles imported. */
        .forum-editor{border:1px solid #d9e1eb;border-radius:10px;background:#fff;overflow:hidden}
        .forum-editor-toolbar{display:none;align-items:center;flex-wrap:wrap;gap:3px;padding:6px 8px;border-bottom:1px solid #e5eaf0;background:#f9fbfd}
        .forum-editor-toolbar button,.forum-editor-toolbar select{height:30px;min-width:32px;padding:3px 7px;background:transparent;border:1px solid transparent;border-radius:6px;color:#49617c;font:inherit;font-size:13px;cursor:pointer}
        .forum-editor-toolbar button:hover,.forum-editor-toolbar button.is-active{background:#e8eef5;border-color:#d5e0ec}
        .forum-editor-toolbar button:focus-visible,.forum-editor-toolbar select:focus-visible{outline:2px solid #739cc4;outline-offset:1px}
        .forum-editor-toolbar select{border-color:#dce5ee;min-width:94px;max-width:145px}
        .forum-editor-divider{height:19px;width:1px;background:#dbe3ec;margin:0 3px}
        .forum-editor-canvas{display:none;min-height:135px}
        .forum-editor-canvas .tiptap{min-height:135px;padding:12px 14px;outline:none;overflow-wrap:anywhere}
        .forum-editor-canvas .tiptap:focus-visible{box-shadow:inset 0 0 0 2px #aec6df}
        .forum-editor-canvas .tiptap p{margin:0 0 .65em}
        .forum-editor-canvas .tiptap p:last-child{margin-bottom:0}
        .forum-editor-canvas .tiptap ul,.forum-editor-canvas .tiptap ol{padding-left:22px}
        .forum-editor-canvas .tiptap blockquote{border-left:3px solid #9eb6ce;padding-left:12px;margin:12px 0;color:#62778f}
        .forum-editor-canvas .tiptap pre{background:#edf1f6;padding:10px;border-radius:6px;white-space:pre-wrap;overflow-wrap:anywhere}
        .forum-editor-canvas .tiptap a,.forum-post-rich a{text-decoration:underline;text-underline-offset:2px}
        .forum-editor[data-editor-ready="1"] .forum-editor-toolbar{display:flex}
        .forum-editor[data-editor-ready="1"] .forum-editor-canvas{display:block}
        .forum-editor[data-editor-ready="1"] .forum-editor-fallback{display:none}
        .forum-editor-fallback{padding:10px}
        .forum-editor-fallback textarea{min-height:135px}
        .forum-editor-status{display:block;padding:0 10px;color:#ad423b;font-size:12px}
        .forum-editor-status:empty{display:none}
        .forum-editor-link-dialog{padding:18px;border:1px solid #dce4ee;border-radius:12px;width:min(400px,calc(100% - 24px));color:#27374b;background:#fff}
        .forum-editor-link-dialog::backdrop{background:rgba(19,38,58,.52)}
        .forum-editor-link-dialog h3{font-size:16px;margin:0 0 14px}
        .forum-editor-link-dialog label{display:grid;gap:7px;font-size:13px;color:#536982}
        .forum-editor-link-dialog input{font:inherit;padding:9px;border:1px solid #ced8e3;border-radius:7px;width:100%}
        .forum-posting-as{color:#74869a;font-size:12px;margin:0}
        .forum-post-rich{white-space:normal}
        .forum-post-rich p{margin:0 0 .8em}
        .forum-post-rich p:last-child{margin-bottom:0}
        .forum-post-rich ul,.forum-post-rich ol{padding-left:24px;margin:.4em 0}
        .forum-post-rich blockquote{margin:12px 0;border-left:3px solid #9eb6ce;padding:5px 0 5px 12px;color:#576b82;background:#f8fafc}
        .forum-post-rich pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#eef2f6;border-radius:7px;padding:12px}
        .forum-post-rich code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:.93em}
        .forum-post-rich pre code{background:none}
    </style>
    <script src="{{ asset('assets/modules/forum/structure.js') }}?v={{ cms_version() }}" defer></script>
    @stack('forum-editor-scripts')
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
