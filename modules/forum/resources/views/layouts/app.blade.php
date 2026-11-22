<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ locale_direction() }}"
      data-forum-theme="{{ app(\App\Support\Themes\ThemeManager::class)->name() }}"
      data-forum-scheme="{{ app(\App\Support\Themes\ThemeManager::class)->name() === 'default' ? 'dark' : 'light' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('module-forum::messages.title')) — {{ site_name() }}</title>
    @if(site_favicon_url())
        <link rel="icon" href="{{ site_favicon_url() }}">
    @endif
    <script src="{{ asset('assets/modules/forum/scheme.js') }}?v={{ cms_version() }}"></script>
    <style>
        @font-face{font-family:Inter;src:url("{{ asset('assets/admin/fonts/inter-variable.woff2') }}") format("woff2");font-display:swap}
        :root{font-family:Inter,system-ui,sans-serif;color:#27374b;background:#f3f5f8}
        *{box-sizing:border-box}
        body{margin:0;font-size:15px;line-height:1.5}
        a{color:#365b83;text-decoration:none}a:hover{text-decoration:underline}
        .forum-top{background:#fff;border-bottom:1px solid #dfe5ed}
        .forum-top-inner{max-width:1130px;margin:auto;min-height:68px;padding:10px 24px;display:flex;gap:28px;align-items:center}
        .forum-brand{display:flex;align-items:center;gap:12px;min-width:0;max-width:100%;color:#27374b}
        .forum-brand img{width:40px;height:40px;object-fit:contain}
        .forum-brand-wide img{width:auto;max-width:180px;height:auto;max-height:44px}
        .forum-brand-copy{display:flex;flex-direction:column;min-width:0}
        .forum-brand-copy strong{font:700 19px/1.25 Georgia,"PT Serif","Times New Roman",serif;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .forum-brand-copy small{font-size:14px;font-weight:400;opacity:.85}
        .forum-nav{display:flex;align-items:center;gap:4px;margin-right:auto}
        .forum-nav a{padding:7px 12px;border-radius:8px;color:#4a6078;font-size:14px;font-weight:600}
        .forum-nav a:hover{background:#f1f5f9;text-decoration:none}
        .forum-nav a[aria-current="page"]{background:#e8eef5;color:#27374b}
        .forum-nav .forum-nav-site{display:none}
        .forum-actions{display:flex;align-items:center;gap:14px;font-size:13px}
        .forum-site-link{color:#5b6d82}
        /* Language menu: the same component, look and motion as the public website.
           Light values follow Kaev Aurelia; the dark rules below follow its night scheme
           and the default dark public theme. */
        .language-menu{position:relative}
        .language-menu>summary{display:inline-flex;align-items:center;gap:6px;min-height:34px;padding:0 11px 0 13px;border:1px solid rgba(155,126,76,.20);border-radius:999px;background:rgba(255,253,247,.54);color:#292b2a;font-size:11px;font-weight:800;letter-spacing:.06em;list-style:none;cursor:pointer}
        .language-menu>summary::-webkit-details-marker{display:none}
        .language-menu>summary:hover,.language-menu[open]>summary{border-color:#b58a43}
        .language-menu>summary:focus-visible{outline:2px solid #b58a43;outline-offset:2px}
        .language-menu-chevron{color:#89847b;transition:transform .3s cubic-bezier(.16,.84,.3,1)}
        .language-menu[open] .language-menu-chevron{transform:rotate(180deg)}
        .language-menu-list{position:absolute;top:calc(100% + 8px);right:0;z-index:60;display:grid;min-width:176px;padding:6px;border:1px solid rgba(155,126,76,.20);border-radius:14px;background:#fffdf8;box-shadow:0 22px 60px rgba(50,61,53,.16)}
        .language-menu-list a{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:9px 12px;border-radius:10px;color:#292b2a;font-size:13px;text-decoration:none}
        .language-menu-list a:hover{background:rgba(216,199,161,.2);text-decoration:none}
        .language-menu-list a small{color:#89847b;font-size:10px;font-weight:800;letter-spacing:.08em}
        .language-menu-list a.active{background:#292b2a;color:#fffdf8}
        .language-menu-list a.active small{color:rgba(255,253,248,.7)}
        [dir="rtl"] .language-menu-list{right:auto;left:0}
        @keyframes forum-language-pop{from{opacity:0;transform:translate3d(0,-10px,0) scale(.96)}to{opacity:1;transform:none}}
        @keyframes forum-language-row{from{opacity:0;transform:translate3d(0,-5px,0)}to{opacity:1;transform:none}}
        .language-menu[open]>.language-menu-list{transform-origin:top right;animation:forum-language-pop .34s cubic-bezier(.16,.84,.3,1) both}
        [dir="rtl"] .language-menu[open]>.language-menu-list{transform-origin:top left}
        .language-menu[open]>.language-menu-list>a{animation:forum-language-row .3s cubic-bezier(.16,.84,.3,1) both}
        .language-menu[open]>.language-menu-list>a:nth-child(1){animation-delay:.05s}
        .language-menu[open]>.language-menu-list>a:nth-child(2){animation-delay:.09s}
        .language-menu[open]>.language-menu-list>a:nth-child(3){animation-delay:.13s}
        .language-menu[open]>.language-menu-list>a:nth-child(4){animation-delay:.17s}
        .language-menu[open]>.language-menu-list>a:nth-child(n+5){animation-delay:.21s}
        @media(prefers-reduced-motion:reduce){.language-menu-chevron{transition:none}.language-menu[open]>.language-menu-list,.language-menu[open]>.language-menu-list>a{animation:none!important}}
        .forum-identity{display:inline-flex;align-items:center;gap:9px;min-width:0;max-width:240px;padding:4px 12px 4px 4px;border:1px solid #e1e7ef;border-radius:999px;color:#27374b}
        a.forum-identity:hover{background:#f5f8fc;text-decoration:none}
        .forum-identity .forum-avatar{flex:none;width:30px;height:30px;font-size:13px;font-weight:700;color:#405b79}
        .forum-identity-copy{display:grid;min-width:0;line-height:1.2}
        .forum-identity-name{font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .forum-identity-role{color:#7b8ca0;font-size:11px}
        .forum-icon-button{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border:1px solid #d9e1eb;border-radius:10px;background:#f5f8fc;color:#405b79}
        .forum-icon-button:hover{background:#edf3fa;text-decoration:none}
        @media(max-width:760px){.forum-top-inner{flex-wrap:wrap;gap:10px 12px;padding:10px 16px}.forum-brand{flex:1 1 0;min-width:0}.forum-brand img{width:34px;height:34px}.forum-brand-copy strong{font-size:17px}.forum-actions{gap:10px}.forum-site-link{display:none}.forum-identity{padding:3px}.forum-identity-copy{display:none}.forum-nav{order:3;width:100%;margin:0;padding-top:8px;border-top:1px solid #edf0f3}.forum-nav .forum-nav-site{display:inline-block;margin-left:auto}}
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
        .forum-category-heading h2{margin:0;font-size:clamp(18px,2vw,22px);line-height:1.3;letter-spacing:-.02em;overflow-wrap:anywhere}
        .forum-category-heading p{margin:3px 0 0;color:#74869a;font-size:12px}
        .forum-category-heading+.forum-panel{border-radius:0 0 10px 10px;border-top:0}
        .forum-section-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;border-bottom:1px solid #edf0f3}
        .forum-section-row:last-child{border-bottom:0}
        .forum-section-main{display:flex;align-items:center;gap:14px;min-width:0}
        .forum-section-icon{display:grid;place-items:center;flex:0 0 42px;height:42px;background:#eaf1f8;color:#42698f;border-radius:12px}
        .forum-section-title{font-size:16px;font-weight:670;overflow-wrap:anywhere}
        .forum-section-main p{color:#586b80;margin:4px 0 0;font-size:14px;overflow-wrap:anywhere}
        .forum-section-empty{font-size:12px;color:#97a4b3}
        .forum-actor-switch{margin-top:28px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;color:#657a91;font-size:13px}
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
        .forum-section-summary small{font-size:12px;color:inherit}
        .forum-section-summary>span{font-weight:650;font-variant-numeric:tabular-nums}
        .forum-compose{padding:20px;display:flex;flex-direction:column;gap:17px}
        .forum-compose-field{display:flex;flex-direction:column;gap:7px;color:#536982;font-size:13px}
        .forum-compose-field input,.forum-compose-field textarea{font:inherit;font-size:14px;color:#27374b;border:1px solid #ced8e3;border-radius:8px;background:#fff;padding:11px 12px;width:100%;min-width:0}
        .forum-compose-field textarea{resize:vertical;min-height:125px}
        .forum-compose-field input:focus,.forum-compose-field textarea:focus{outline:2px solid #739cc4;outline-offset:1px}
        .forum-pagination{display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:5px;margin:20px 0}
        .forum-pagination a,.forum-pagination strong,.forum-page-disabled{padding:6px 10px;border-radius:7px;border:1px solid #dfe5ed;background:#fff;font-size:13px}
        .forum-pagination strong{background:#e7eef8;color:#365b83}
        .forum-page-disabled{color:#9caab8;background:transparent}
        .forum-post{display:grid;grid-template-columns:184px minmax(0,1fr);border-bottom:1px solid #edf0f3;scroll-margin-top:20px}
        .forum-post-author-card{display:flex;flex-direction:column;align-items:center;gap:10px;padding:20px 16px;border-right:1px solid #edf0f3;background:#fafbfd;text-align:center;min-width:0}
        .forum-post-avatar{width:64px;height:64px;font-size:24px;font-weight:700;color:#405b79}
        .forum-post-author-copy{display:grid;justify-items:center;gap:6px;min-width:0;max-width:100%}
        .forum-post-author-copy .forum-post-author{overflow-wrap:anywhere}
        .forum-post-badges{display:flex;flex-wrap:wrap;justify-content:center;gap:4px}
        .forum-post-badges:empty{display:none}
        .forum-tag-author{background:#e3f1e7;color:#2f6a43}
        .forum-post-stats{display:grid;gap:2px;margin:2px 0 0;color:#8999ac;font-size:12px}
        .forum-post-stats div{display:flex;flex-wrap:wrap;justify-content:center;gap:4px}
        .forum-post-stats dt,.forum-post-stats dd{margin:0}
        .forum-post-stats dd{color:#5b6d82;font-weight:600}
        .forum-post-main{display:flex;flex-direction:column;min-width:0;padding:19px 22px}
        .forum-post-first{box-shadow:inset 3px 0 0 #365b83}
        .forum-post-first .forum-post-author-card{background:#f2f6fb}
        .forum-post-number{margin-left:auto;color:#95a3b4;font-size:12px;font-weight:600;text-decoration:none}
        .forum-post-number:hover{color:#365b83;text-decoration:none}
        .forum-post-number.is-copied{color:#2f6a43}
        .forum-post:last-child{border-bottom:0}
        .forum-post-header{display:flex;align-items:center;flex-wrap:wrap;gap:9px;margin-bottom:13px}
        .forum-post-author{color:#304f71;font-weight:700}
        .forum-post-date{color:#95a3b4;font-size:12px}
        .forum-topic-moderation{margin:0 0 18px}
        .forum-topic-moderation summary{cursor:pointer}
        .forum-topic-moderation-content{display:grid;gap:13px;padding:15px 18px}
        .forum-topic-moderation .forum-tools form{margin:0}
        .forum-move-form{display:flex;gap:9px;align-items:center;flex-wrap:wrap}
        .forum-move-form label{font-size:13px;color:#566c83}
        .forum-move-form select{font:inherit;min-width:180px;max-width:100%;padding:8px;border:1px solid #d9e1eb;border-radius:8px}
        .forum-move-confirm{display:flex;align-items:center;gap:5px}
        .forum-notice-warning{background:#fff5db;color:#76500e}
        .forum-post-edited{display:block;margin-top:6px;color:#8999ac;font-size:11px}
        .forum-post-removed{color:#8594a5;font-style:italic}
        .forum-post-tools{display:flex;align-items:center;flex-wrap:wrap;gap:7px;margin-top:auto;padding-top:14px}
        .forum-post-tools form{margin:0}
        .forum-post-body{white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word;color:#2d3a4c;line-height:1.65}
        .forum-reply-panel{margin-top:25px}
        .forum-read-only{background:#edf0f4;color:#788a9e;padding:12px 16px;border-radius:9px;font-size:13px;margin-top:24px}
        .forum-join{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px 18px;padding:16px 18px;color:#405b79;font-size:14px}
        .forum-join p{margin:0}
        .forum-join-actions{display:flex;flex-wrap:wrap;gap:8px}
        @media(max-width:650px){.forum-topic-row{flex-direction:column;align-items:flex-start}.forum-topic-stats{text-align:left}.forum-section-summary{text-align:left}.forum-post{grid-template-columns:minmax(0,1fr)}.forum-post-author-card{flex-direction:row;align-items:center;gap:12px;padding:14px 16px;border-right:0;border-bottom:1px solid #edf0f3;text-align:left}.forum-post-avatar{width:42px;height:42px;font-size:17px}.forum-post-author-copy{justify-items:start}.forum-post-badges,.forum-post-stats div{justify-content:flex-start}.forum-post-stats{grid-auto-flow:column;gap:12px}.forum-post-main{padding:14px 16px}}

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

        /* F10: reusable forum controls follow the same spacing and typography. */
        .forum-skip-link{position:absolute;left:12px;top:-80px;z-index:100;padding:9px 14px;background:#fff;color:#27374b;border:2px solid #739cc4;border-radius:8px}
        .forum-skip-link:focus-visible{top:12px}
        :where(.forum-body,.forum-top) :where(a,button,input,textarea,select,summary):focus-visible{outline:2px solid #6396cd;outline-offset:3px}
        .forum-breadcrumb{flex-wrap:wrap;overflow-wrap:anywhere}
        .forum-topic-main{min-width:0;flex:1}
        .forum-search-form{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:end;gap:10px;padding:18px 20px;margin:0 0 18px}
        .forum-search-form label{grid-column:1/-1;margin-bottom:-4px;color:#657a91;font-size:13px}
        .forum-search-form input{width:100%;min-width:0;padding:10px 12px;border:1px solid #ced8e3;border-radius:8px;font:inherit;color:#27374b;background:#fff}
        .forum-inline-confirm{position:relative;min-width:0}
        .forum-inline-confirm summary{list-style:none;width:max-content;max-width:100%;user-select:none}
        .forum-inline-confirm summary::-webkit-details-marker{display:none}
        .forum-confirm-content{display:grid;justify-items:start;gap:10px;max-width:330px;margin-top:8px;padding:12px 14px;border:1px solid #edc4c4;border-radius:9px;background:#fffafa;color:#823b3b;font-size:13px}
        .forum-confirm-content p{margin:0;line-height:1.5}
        .forum-section-row:focus-within,.forum-topic-row:focus-within{background:rgba(53,91,133,.035)}
        .forum-topic-row .forum-tag{display:inline-block;margin:0 0 0 7px;vertical-align:middle}
        .forum-footer{padding:0 16px 24px}
        @media(max-width:650px){
            .forum-brand-wide img{max-width:132px;max-height:40px}
            .forum-search-form{grid-template-columns:1fr;padding:15px}
            .forum-search-form label{grid-column:auto}
            .forum-search-form button{justify-self:start}
            .forum-move-form{display:grid;grid-template-columns:minmax(0,1fr)}
            .forum-move-form select{width:100%;min-width:0}
            .forum-dialog-actions{flex-wrap:wrap}
            .forum-confirm-content{max-width:100%}
        }
        @media(max-width:390px){.forum-body{padding:0 12px}.forum-brand{gap:7px}.forum-top-inner{padding:12px}.forum-topic-row{padding:14px}.forum-panel-heading{padding:12px 15px}}

        /* The default CMS public theme is dark. Aurelia uses its own saved
           kaevcms.site.scheme choice, loaded before paint by forum/scheme.js. */
        :root[data-forum-scheme="dark"]{color-scheme:dark;color:#e0e8f1;background:#0e141c}
        :root[data-forum-scheme="dark"] a{color:#a3c6ec}
        :root[data-forum-scheme="dark"] .forum-brand{color:#eff3f8}
        :root[data-forum-scheme="dark"] .forum-top{background:#151f2a;border-bottom-color:#2a3949}
        :root[data-forum-scheme="dark"] .forum-nav a,
        :root[data-forum-scheme="dark"] .forum-site-link{color:#a9bdd3}
        :root[data-forum-scheme="dark"] .forum-nav a:hover{background:#1d2a37}
        :root[data-forum-scheme="dark"] .forum-nav a[aria-current="page"]{background:#263548;color:#eef3f8}
        :root[data-forum-scheme="dark"] .forum-nav{border-top-color:#2a3949}
        :root[data-forum-theme="kaev-aurelia"][data-forum-scheme="dark"] .language-menu>summary{border-color:rgba(190,172,235,.15);background:rgba(25,22,34,.72);color:#ece8f5}
        :root[data-forum-theme="kaev-aurelia"][data-forum-scheme="dark"] .language-menu>summary:hover,
        :root[data-forum-theme="kaev-aurelia"][data-forum-scheme="dark"] .language-menu[open]>summary{border-color:#b9a3f1}
        :root[data-forum-theme="kaev-aurelia"][data-forum-scheme="dark"] .language-menu>summary:focus-visible{outline-color:#b9a3f1}
        :root[data-forum-theme="kaev-aurelia"][data-forum-scheme="dark"] .language-menu-chevron,
        :root[data-forum-theme="kaev-aurelia"][data-forum-scheme="dark"] .language-menu-list a small{color:#9690a8}
        :root[data-forum-theme="kaev-aurelia"][data-forum-scheme="dark"] .language-menu-list{border-color:rgba(190,172,235,.15);background:#181520;box-shadow:0 24px 64px rgba(0,0,0,.6)}
        :root[data-forum-theme="kaev-aurelia"][data-forum-scheme="dark"] .language-menu-list a{color:#ece8f5}
        :root[data-forum-theme="kaev-aurelia"][data-forum-scheme="dark"] .language-menu-list a:hover{background:rgba(185,163,241,.12)}
        :root[data-forum-theme="kaev-aurelia"][data-forum-scheme="dark"] .language-menu-list a.active{background:linear-gradient(135deg,#cdbdf7,#a58de6);color:#17121f}
        :root[data-forum-theme="kaev-aurelia"][data-forum-scheme="dark"] .language-menu-list a.active small{color:rgba(23,18,31,.72)}
        :root[data-forum-theme="default"] .language-menu>summary{padding:0 10px 0 12px;border-color:rgba(255,255,255,.18);border-radius:7px;background:rgba(0,0,0,.16);color:rgba(255,255,255,.86)}
        :root[data-forum-theme="default"] .language-menu>summary:hover,
        :root[data-forum-theme="default"] .language-menu[open]>summary{border-color:#b27a2d}
        :root[data-forum-theme="default"] .language-menu>summary:focus-visible{outline-color:#b27a2d}
        :root[data-forum-theme="default"] .language-menu-chevron{color:rgba(255,255,255,.6);transition-duration:.16s}
        :root[data-forum-theme="default"] .language-menu-list{border-color:#343a3d;border-radius:8px;background:#0d1214;box-shadow:0 18px 50px rgba(0,0,0,.45)}
        :root[data-forum-theme="default"] .language-menu[open]>.language-menu-list,
        :root[data-forum-theme="default"] .language-menu[open]>.language-menu-list>a{animation:none}
        :root[data-forum-theme="default"] .language-menu-list a{border-radius:5px;color:#d8d9d7}
        :root[data-forum-theme="default"] .language-menu-list a small{color:#858b8d}
        :root[data-forum-theme="default"] .language-menu-list a:hover{background:rgba(255,255,255,.06)}
        :root[data-forum-theme="default"] .language-menu-list a.active{background:#b27a2d;color:#15110b}
        :root[data-forum-theme="default"] .language-menu-list a.active small{color:rgba(21,17,11,.7)}
        :root[data-forum-scheme="dark"] .forum-identity{border-color:#324252;color:#e2e8f0}
        :root[data-forum-scheme="dark"] a.forum-identity:hover{background:#1d2a37}
        :root[data-forum-scheme="dark"] .forum-identity-role{color:#8fa3b8}
        :root[data-forum-scheme="dark"] .forum-avatar{background:#2e4057;color:#c9d9ee}
        :root[data-forum-scheme="dark"] .forum-icon-button{background:#1d2a37;border-color:#324252;color:#c9d9ee}
        :root[data-forum-scheme="dark"] .forum-icon-button:hover{background:#263548}
        :root[data-forum-scheme="dark"] .forum-panel,
        :root[data-forum-scheme="dark"] .forum-dialog{background:#192430;border-color:#324252;color:#e2e8f0;box-shadow:0 10px 30px rgba(0,0,0,.1)}
        :root[data-forum-scheme="dark"] .forum-panel-heading,
        :root[data-forum-scheme="dark"] .forum-category-heading{background:#202d3c;border-color:#34465a;color:#e4eaf3}
        :root[data-forum-scheme="dark"] .forum-category-heading h2{color:#e5edf5}
        :root[data-forum-scheme="dark"] .forum-section-row,
        :root[data-forum-scheme="dark"] .forum-topic-row,
        :root[data-forum-scheme="dark"] .forum-post{border-bottom-color:#304052}
        :root[data-forum-scheme="dark"] .forum-post-author-card{background:#1d2a37;border-color:#304052}
        :root[data-forum-scheme="dark"] .forum-post-author{color:#dbe7f4}
        :root[data-forum-scheme="dark"] .forum-post-first{box-shadow:inset 3px 0 0 #6a9ad0}
        :root[data-forum-scheme="dark"] .forum-post-first .forum-post-author-card{background:#203041}
        :root[data-forum-scheme="dark"] .forum-post-avatar{background:#2e4057;color:#c9d9ee}
        :root[data-forum-scheme="dark"] .forum-post-stats{color:#8fa3b8}
        :root[data-forum-scheme="dark"] .forum-post-stats dd{color:#c3d2e2}
        :root[data-forum-scheme="dark"] .forum-tag-author{background:#24412f;color:#a9dfc0}
        :root[data-forum-scheme="dark"] .forum-post-number{color:#8fa3b8}
        :root[data-forum-scheme="dark"] .forum-post-number:hover{color:#a3c6ec}
        :root[data-forum-scheme="dark"] .forum-button,
        :root[data-forum-scheme="dark"] .forum-pagination a,
        :root[data-forum-scheme="dark"] .forum-pagination strong{background:#25364a;border-color:#3b5066;color:#d5e6f9}
        :root[data-forum-scheme="dark"] .forum-button:hover{background:#30455f}
        :root[data-forum-scheme="dark"] .forum-button-primary{background:#416c9e;border-color:#537fab;color:#fff}
        :root[data-forum-scheme="dark"] .forum-button-primary:hover{background:#5480b3}
        :root[data-forum-scheme="dark"] .forum-button-danger{color:#f0aaa5;border-color:#86575b}
        :root[data-forum-scheme="dark"] .forum-tag{background:#2e4057;color:#c9d9ee}
        :root[data-forum-scheme="dark"] .forum-section-icon{background:#283b51;color:#b8d5f3}
        :root[data-forum-scheme="dark"] .forum-panel-body,
        :root[data-forum-scheme="dark"] .forum-post-body{color:#dbe4ee}
        :root[data-forum-scheme="dark"] .forum-desc,
        :root[data-forum-scheme="dark"] .forum-help,
        :root[data-forum-scheme="dark"] .forum-post-date,
        :root[data-forum-scheme="dark"] .forum-topic-meta,
        :root[data-forum-scheme="dark"] .forum-topic-stats,
        :root[data-forum-scheme="dark"] .forum-section-summary,
        :root[data-forum-scheme="dark"] .forum-section-main p,
        :root[data-forum-scheme="dark"] .forum-breadcrumb,
        :root[data-forum-scheme="dark"] .forum-actor-switch,
        :root[data-forum-scheme="dark"] .forum-post-edited,
        :root[data-forum-scheme="dark"] .forum-posting-as,
        :root[data-forum-scheme="dark"] .forum-footer{color:#9dabbc}
        :root[data-forum-scheme="dark"] .forum-form-grid label,
        :root[data-forum-scheme="dark"] .forum-compose-field,
        :root[data-forum-scheme="dark"] .forum-search-form label,
        :root[data-forum-scheme="dark"] .forum-move-form label{color:#b9c7d6}
        :root[data-forum-scheme="dark"] :where(.forum-form-grid,.forum-compose-field,.forum-search-form,.forum-move-form) :where(input,textarea,select),
        :root[data-forum-scheme="dark"] .forum-editor-link-dialog input{background:#121b26;border-color:#3a4e63;color:#e2eaf3}
        :root[data-forum-scheme="dark"] .forum-editor,
        :root[data-forum-scheme="dark"] .forum-editor-link-dialog{background:#172332;border-color:#37495d;color:#e2eaf3}
        :root[data-forum-scheme="dark"] .forum-editor-toolbar{background:#202e3f;border-color:#3b4d63}
        :root[data-forum-scheme="dark"] .forum-editor-toolbar button,
        :root[data-forum-scheme="dark"] .forum-editor-toolbar select{color:#d7e4f2}
        :root[data-forum-scheme="dark"] .forum-editor-toolbar button:hover,
        :root[data-forum-scheme="dark"] .forum-editor-toolbar button.is-active{background:#31445c;border-color:#47627e}
        :root[data-forum-scheme="dark"] .forum-editor-canvas .tiptap,
        :root[data-forum-scheme="dark"] .forum-editor-link-dialog label{color:#dce6f0}
        :root[data-forum-scheme="dark"] .forum-editor-canvas .tiptap pre,
        :root[data-forum-scheme="dark"] .forum-post-rich pre,
        :root[data-forum-scheme="dark"] .forum-post-rich blockquote{background:#222f40;color:#d5e3f2}
        :root[data-forum-scheme="dark"] .forum-confirm-content{background:#30252a;border-color:#86575b;color:#f5bdba}
        :root[data-forum-scheme="dark"] .forum-notice{background:#1c392e;color:#a9dfc0}
        :root[data-forum-scheme="dark"] .forum-notice-error{background:#422b2d;color:#f3b8b4}
        :root[data-forum-scheme="dark"] .forum-notice-warning{background:#4b3c24;color:#f4d89d}
        :root[data-forum-scheme="dark"] .forum-read-only{background:#263448;color:#b2c4d9}
        :root[data-forum-scheme="dark"] .forum-join{color:#d5e3f2}
        :root[data-forum-scheme="dark"] .forum-pagination strong{background:#355372}
        :root[data-forum-scheme="dark"] .forum-skip-link{background:#25364a;color:#fff;border-color:#6a9ad0}
    </style>
    <script src="{{ asset('assets/modules/forum/structure.js') }}?v={{ cms_version() }}" defer></script>
    @stack('forum-editor-scripts')
</head>
<body>
<a class="forum-skip-link" href="#forum-main">{{ __('module-forum::messages.skip_to_content') }}</a>
<header class="forum-top">
    <div class="forum-top-inner">
        @php
            $customLogo = site_logo_url();
            $wideLogo = $customLogo && site_logo_is_wide();
            $brandLogo = $customLogo ?? (app(\App\Support\Themes\AccountThemeManager::class)->name() === 'kaev-aurelia'
                ? account_theme_asset('assets/images/kaev-logo.webp')
                : asset('assets/images/kaev-logo.webp'));
        @endphp
        <a @class(['forum-brand', 'forum-brand-wide' => $wideLogo]) href="{{ route('modules.forum.index') }}" aria-label="{{ site_name() }} — {{ __('module-forum::messages.brand_subtitle') }}">
            <img src="{{ $brandLogo }}" alt="">
            @unless($wideLogo)
                <span class="forum-brand-copy">
                    <strong>{{ site_name() }}</strong>
                    <small>{{ __('module-forum::messages.brand_subtitle') }}</small>
                </span>
            @endunless
        </a>
        <nav class="forum-nav" aria-label="{{ __('module-forum::messages.title') }}">
            <a href="{{ route('modules.forum.index') }}" @if(! request()->routeIs('modules.forum.search')) aria-current="page" @endif>{{ __('module-forum::messages.title') }}</a>
            <a href="{{ route('modules.forum.search') }}" @if(request()->routeIs('modules.forum.search')) aria-current="page" @endif>{{ __('module-forum::messages.forum_search') }}</a>
            <a class="forum-nav-site" href="{{ public_route('home') }}">{{ __('module-forum::messages.website') }}</a>
        </nav>
        <div class="forum-actions">
            <a class="forum-site-link" href="{{ public_route('home') }}">{{ __('module-forum::messages.website') }}</a>
            <x-language-menu />
            @if($actor->isGuest())
                <a class="forum-button forum-button-primary" href="{{ public_route('login') }}">{{ __('module-forum::messages.login') }}</a>
            @else
                @php($identityTag = $actor->user ? 'a' : 'span')
                <{{ $identityTag }} class="forum-identity" @if($actor->user) href="{{ public_route('account') }}" title="{{ __('module-forum::messages.account') }}" @endif>
                    @if($actor->user)
                        <x-account-avatar :user="$actor->user" :fallback="$actor->user->publicName()" class="forum-avatar" />
                    @else
                        <span class="account-avatar forum-avatar" aria-hidden="true"><span>{{ mb_strtoupper(mb_substr($actor->name(), 0, 1)) }}</span></span>
                    @endif
                    <span class="forum-identity-copy">
                        <span class="forum-identity-name">{{ $actor->name() }}</span>
                        @if($actor->label())<span class="forum-identity-role">{{ $actor->label() }}</span>@endif
                    </span>
                </{{ $identityTag }}>
            @endif
            @if($actor->isOwner())
                <a class="forum-icon-button" data-forum-owner-settings
                   href="{{ route('admin.module-pages.forum.settings', ['adminPath' => app(\App\Services\Admin\AdminPathSettings::class)->path()]) }}"
                   title="{{ __('module-forum::messages.settings_link') }}" aria-label="{{ __('module-forum::messages.settings_link') }}">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                </a>
            @endif
        </div>
    </div>
</header>
<main class="forum-body" id="forum-main" tabindex="-1">
    @yield('content')
    @include('module-forum::partials.actor-switch')
</main>
<footer class="forum-footer">{{ site_name() }} · {{ __('module-forum::messages.title') }}</footer>
</body>
</html>
