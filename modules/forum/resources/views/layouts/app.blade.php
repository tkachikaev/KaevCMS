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
        .forum-actions{display:flex;align-items:center;gap:14px;margin-left:auto;font-size:13px}
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
        .forum-identity:hover,.forum-profile-menu[open]>.forum-identity{background:#f5f8fc;text-decoration:none}
        .forum-identity .forum-avatar{flex:none;width:30px;height:30px;font-size:13px;font-weight:700;color:#405b79}
        .forum-identity-copy{display:grid;min-width:0;line-height:1.2}
        .forum-identity-name{font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .forum-identity-role{color:#7b8ca0;font-size:11px}
        /* Profile menu: the same panel, links and opening motion as the player account menu. */
        .forum-profile-menu{position:relative}
        .forum-profile-menu>summary{list-style:none;cursor:pointer}
        .forum-profile-menu>summary::-webkit-details-marker{display:none}
        .forum-profile-menu>summary:focus-visible{outline:2px solid #355b85;outline-offset:2px}
        .forum-profile-chevron{flex:none;color:#7b8ca0;transition:transform .2s ease}
        .forum-profile-menu[open] .forum-profile-chevron{transform:rotate(180deg)}
        .forum-profile-dropdown{position:absolute;right:0;top:calc(100% + 8px);z-index:60;width:260px;padding:8px;border:1px solid #dfe5ed;border-radius:12px;background:#fff;box-shadow:0 12px 32px rgba(23,37,56,.14);font-size:15px}
        .forum-profile-head{display:flex;flex-direction:column;padding:8px 10px 12px;margin-bottom:6px;border-bottom:1px solid #edf0f3;color:#27374b}
        .forum-profile-head strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .forum-profile-head small{color:#7b8ca0;font-size:14px;overflow-wrap:anywhere}
        .forum-profile-dropdown a,.forum-profile-dropdown button{width:100%;display:flex;align-items:center;min-height:44px;padding:0 12px;border:0;border-radius:10px;background:transparent;color:#27374b;font:inherit;text-align:left;cursor:pointer}
        .forum-profile-dropdown a:hover,.forum-profile-dropdown button:hover{background:#f3f6fa;text-decoration:none}
        .forum-profile-dropdown .forum-profile-balance{display:grid;grid-template-columns:36px minmax(0,1fr);gap:10px;min-height:0;margin:4px 0 8px;padding:10px;border:1px solid #edf0f3;background:#f8fafc}
        .forum-profile-balance-icon{width:36px;height:36px;display:grid;place-items:center}
        .forum-profile-balance-icon img{width:32px;height:32px;object-fit:contain}
        .forum-profile-balance>span:last-child{min-width:0;display:flex;flex-direction:column;line-height:1.3}
        .forum-profile-balance small{color:#7b8ca0;font-size:14px}
        .forum-profile-balance strong{overflow:hidden;color:#9a6f2e;font-size:16px;text-overflow:ellipsis;white-space:nowrap}
        .forum-profile-dropdown form{margin:6px 0 0;padding-top:6px;border-top:1px solid #edf0f3}
        [dir="rtl"] .forum-profile-dropdown{right:auto;left:0}
        @keyframes forum-profile-pop{from{opacity:0;transform:translate3d(0,-10px,0) scale(.96)}to{opacity:1;transform:none}}
        @keyframes forum-profile-row{from{opacity:0;transform:translate3d(0,-5px,0)}to{opacity:1;transform:none}}
        .forum-profile-menu[open]>.forum-profile-dropdown{transform-origin:top right;animation:forum-profile-pop .34s cubic-bezier(.16,.84,.3,1) both}
        .forum-profile-menu[open]>.forum-profile-dropdown>*{animation:forum-profile-row .3s cubic-bezier(.16,.84,.3,1) both}
        .forum-profile-menu[open]>.forum-profile-dropdown>:nth-child(1){animation-delay:.05s}
        .forum-profile-menu[open]>.forum-profile-dropdown>:nth-child(2){animation-delay:.09s}
        .forum-profile-menu[open]>.forum-profile-dropdown>:nth-child(3){animation-delay:.13s}
        .forum-profile-menu[open]>.forum-profile-dropdown>:nth-child(4){animation-delay:.17s}
        .forum-profile-menu[open]>.forum-profile-dropdown>:nth-child(n+5){animation-delay:.21s}
        @media(prefers-reduced-motion:reduce){.forum-profile-chevron{transition:none}.forum-profile-menu[open]>.forum-profile-dropdown,.forum-profile-menu[open]>.forum-profile-dropdown>*{animation:none!important}}
        @media(max-width:760px){.forum-profile-chevron{display:none}.forum-profile-dropdown{position:fixed;left:13px;right:13px;top:auto;width:auto}}
        @media(max-width:760px){.forum-top-inner{flex-wrap:wrap;gap:10px 12px;padding:10px 16px}.forum-brand{flex:1 1 0;min-width:0}.forum-brand img{width:34px;height:34px}.forum-brand-copy strong{font-size:17px}.forum-actions{gap:10px}.forum-identity{padding:3px}.forum-identity-copy{display:none}}
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
        @media(max-width:650px){.forum-section-row{align-items:stretch;flex-direction:column}.forum-section-row .forum-section-stats{gap:18px;padding-left:56px}.forum-section-row .forum-section-stats div{width:auto;display:flex;gap:6px;align-items:baseline;text-align:left}.forum-section-stats dd{margin:0;font-size:14px}.forum-section-row .forum-section-last{width:auto;padding-left:56px}.forum-form-grid,.forum-permissions{grid-template-columns:1fr}.forum-dialog{padding:18px}.forum-tools{gap:4px}}

        .forum-topic-row{display:flex;justify-content:space-between;align-items:center;gap:18px;padding:18px 20px;border-bottom:1px solid #edf0f3}
        .forum-topic-row:last-child{border-bottom:0}
        .forum-topic-main{min-width:0}
        .forum-topic-title{font-weight:680;font-size:15px;overflow-wrap:anywhere}
        .forum-topic-meta{font-size:12px;color:#8493a5;margin:5px 0 0}
        .forum-topic-stats{text-align:right;color:#62758b;font-size:12px;flex-shrink:0}
        .forum-topic-stats small{display:block;color:#899aab;margin-top:5px;line-height:1.55}
        /* Section row: name, topic/message totals and the latest message, like classic forums. */
        .forum-section-main{flex:1 1 auto}
        .forum-section-copy{min-width:0}
        .forum-section-tools{margin-top:8px}
        .forum-section-stats{display:flex;gap:6px;margin:0;flex:none}
        .forum-section-stats div{width:92px;text-align:center}
        .forum-section-stats dt{color:#7b8ca0;font-size:12px}
        .forum-section-stats dd{margin:2px 0 0;color:#27374b;font-size:17px;font-weight:650;font-variant-numeric:tabular-nums}
        .forum-section-last{display:flex;align-items:center;gap:11px;flex:none;width:270px;min-width:0}
        .forum-section-last .forum-section-last-avatar{flex:none;width:40px;height:40px;border-radius:10px;font-size:16px;font-weight:700;color:#405b79}
        .forum-section-last-copy{display:grid;min-width:0;line-height:1.35}
        .forum-section-last-copy a{overflow:hidden;font-size:14px;font-weight:600;text-overflow:ellipsis;white-space:nowrap}
        .forum-section-last-copy small{overflow:hidden;color:#7b8ca0;font-size:13px;text-overflow:ellipsis;white-space:nowrap}
        .forum-section-last-copy small span{color:#4a6078;font-weight:600}
        .forum-section-last-empty{color:#8a9aac;font-size:13px}
        @media(max-width:900px){.forum-section-last{width:220px}.forum-section-stats div{width:74px}}
        /* Topic list in a section: author avatar, title, replies and the latest reply with its avatar. */
        .forum-topic-row-rich{justify-content:flex-start}
        .forum-topic-row-rich .forum-topic-avatar{flex:none;width:42px;height:42px;border-radius:10px;font-size:17px;font-weight:700;color:#405b79}
        .forum-topic-author{color:#4a6078;font-weight:600}
        .forum-topic-pages{display:inline-flex;gap:3px;margin-left:8px;vertical-align:1px}
        .forum-topic-pages a{min-width:20px;padding:0 5px;border:1px solid #dfe5ed;border-radius:5px;color:#5b6d82;font-size:11px;line-height:18px;text-align:center}
        .forum-topic-pages a:hover{border-color:#9db6cf;text-decoration:none}
        .forum-topic-counts{display:grid;gap:2px;width:150px;margin:0;flex:none}
        .forum-topic-counts div{display:flex;align-items:baseline;justify-content:space-between;gap:14px}
        .forum-topic-counts dt{color:#7b8ca0;font-size:13px}
        .forum-topic-counts dd{margin:0;color:#27374b;font-size:15px;font-weight:650;font-variant-numeric:tabular-nums}
        .forum-topic-counts div+div dd{color:#7b8ca0;font-size:13px;font-weight:600}
        .forum-topic-last{display:flex;align-items:center;justify-content:flex-end;gap:11px;width:200px;min-width:0;flex:none}
        .forum-topic-last-copy{display:grid;justify-items:end;min-width:0;line-height:1.35;text-align:right}
        .forum-topic-last-copy a,.forum-topic-last-copy>span{font-size:14px;font-weight:600;white-space:nowrap}
        .forum-topic-last-copy small{max-width:100%;overflow:hidden;color:#7b8ca0;font-size:13px;text-overflow:ellipsis;white-space:nowrap}
        .forum-topic-row-rich .forum-topic-last-avatar{flex:none;width:32px;height:32px;border-radius:8px;font-size:13px;font-weight:700;color:#405b79}
        @media(max-width:900px){.forum-topic-counts{width:124px}.forum-topic-last{width:170px}}
        @media(max-width:650px){.forum-topic-row.forum-topic-row-rich{display:grid;grid-template-columns:42px auto minmax(0,1fr);align-items:center;gap:8px 12px}.forum-topic-row-rich .forum-topic-avatar{grid-row:1/3;align-self:start}.forum-topic-row-rich .forum-topic-main{grid-column:2/4}.forum-topic-row-rich .forum-topic-counts{grid-column:2;width:auto}.forum-topic-row-rich .forum-topic-counts div{justify-content:flex-start;gap:6px}.forum-topic-row-rich .forum-topic-last{grid-column:3;width:auto;min-width:0}}
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
        /* Topic moderation: a menu beside the title; move and delete confirm in dialogs. */
        .forum-topic-heading{align-items:flex-start}
        .forum-topic-heading .forum-tools{margin-top:8px}
        .forum-topic-heading .forum-tools:empty{display:none}
        .forum-mod-menu{position:relative;flex:none}
        .forum-mod-trigger{display:inline-flex;align-items:center;gap:7px;list-style:none;font-weight:600}
        .forum-mod-trigger::-webkit-details-marker{display:none}
        .forum-mod-menu[open]>.forum-mod-trigger{background:#e8eef5;border-color:#c9d7e6}
        .forum-mod-chevron{transition:transform .2s ease}
        .forum-mod-menu[open] .forum-mod-chevron{transform:rotate(180deg)}
        .forum-mod-panel{position:absolute;right:0;top:calc(100% + 8px);z-index:50;display:grid;width:250px;padding:6px;border:1px solid #dfe5ed;border-radius:12px;background:#fff;box-shadow:0 12px 32px rgba(23,37,56,.14)}
        .forum-mod-panel form{margin:0}
        .forum-mod-panel button{width:100%;min-height:40px;padding:0 12px;border:0;border-radius:8px;background:transparent;color:#27374b;font:inherit;font-size:14px;text-align:left;cursor:pointer}
        .forum-mod-panel button:hover{background:#f3f6fa}
        .forum-mod-panel .forum-mod-danger{margin-top:4px;border-top:1px solid #edf0f3;border-radius:0 0 8px 8px;color:#b03a32}
        .forum-mod-panel .forum-mod-danger:hover{background:#fdf1f0}
        .forum-mod-panel .forum-topic-moderation-note{margin:6px 6px 4px;padding-top:8px;border-top:1px solid #edf0f3;line-height:1.45}
        @keyframes forum-mod-pop{from{opacity:0;transform:translate3d(0,-8px,0) scale(.97)}to{opacity:1;transform:none}}
        .forum-mod-menu[open]>.forum-mod-panel{transform-origin:top right;animation:forum-mod-pop .28s cubic-bezier(.16,.84,.3,1) both}
        @media(prefers-reduced-motion:reduce){.forum-mod-menu[open]>.forum-mod-panel{animation:none}.forum-mod-chevron{transition:none}}
        .forum-dialog-field{display:grid;gap:7px;margin:0 0 14px;color:#566c83;font-size:13px}
        .forum-dialog-field select{font:inherit;width:100%;padding:9px;border:1px solid #d9e1eb;border-radius:8px}
        .forum-dialog-note{margin:10px 0 14px;color:#7b8ca0;font-size:13px}
        @media(max-width:650px){.forum-topic-heading{flex-direction:column}.forum-mod-panel{left:0;right:auto;transform-origin:top left}}
        :root[data-forum-scheme="dark"] .forum-mod-menu[open]>.forum-mod-trigger{background:#263548;border-color:#3b4d63}
        :root[data-forum-scheme="dark"] .forum-mod-panel{border-color:#2a3949;background:#151f2a;box-shadow:0 18px 48px rgba(0,0,0,.5)}
        :root[data-forum-scheme="dark"] .forum-mod-panel button{color:#e2e8f0}
        :root[data-forum-scheme="dark"] .forum-mod-panel button:hover{background:#1d2a37}
        :root[data-forum-scheme="dark"] .forum-mod-panel .forum-mod-danger{border-color:#263445;color:#f19a9a}
        :root[data-forum-scheme="dark"] .forum-mod-panel .forum-topic-moderation-note{border-color:#263445}
        :root[data-forum-scheme="dark"] .forum-dialog-field{color:#b9c7d6}
        :root[data-forum-scheme="dark"] .forum-dialog-field select{background:#121b26;border-color:#3a4e63;color:#e2eaf3}
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
        @media(max-width:650px){.forum-topic-row{flex-direction:column;align-items:flex-start}.forum-topic-stats{text-align:left}.forum-post{grid-template-columns:minmax(0,1fr)}.forum-post-author-card{flex-direction:row;align-items:center;gap:12px;padding:14px 16px;border-right:0;border-bottom:1px solid #edf0f3;text-align:left}.forum-post-avatar{width:42px;height:42px;font-size:17px}.forum-post-author-copy{justify-items:start}.forum-post-badges,.forum-post-stats div{justify-content:flex-start}.forum-post-stats{grid-auto-flow:column;gap:12px}.forum-post-main{padding:14px 16px}}

        /* Forum-only presentation for the shared Tiptap editor; no admin styles imported. */
        .forum-editor{border:1px solid #d9e1eb;border-radius:10px;background:#fff}
        .forum-editor-toolbar{display:none;align-items:center;flex-wrap:wrap;gap:3px;padding:6px 8px;border-bottom:1px solid #e5eaf0;border-radius:10px 10px 0 0;background:#f9fbfd}
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
        /* Text color palette: every tone has a light-scheme and a dark-scheme value. */
        [data-color="red"]{--forum-tone:#d32f2f}[data-color="crimson"]{--forum-tone:#b71c3c}[data-color="wine"]{--forum-tone:#8e244d}[data-color="maroon"]{--forum-tone:#7f1d1d}[data-color="rose"]{--forum-tone:#e0457b}[data-color="pink"]{--forum-tone:#d63384}[data-color="fuchsia"]{--forum-tone:#c026d3}[data-color="magenta"]{--forum-tone:#a21caf}[data-color="plum"]{--forum-tone:#7e3b8a}[data-color="purple"]{--forum-tone:#7b3fe4}[data-color="violet"]{--forum-tone:#6d4fd8}[data-color="indigo"]{--forum-tone:#3f51b5}[data-color="navy"]{--forum-tone:#1f3a8a}[data-color="blue"]{--forum-tone:#1e6fd9}[data-color="azure"]{--forum-tone:#0a84c6}[data-color="sky"]{--forum-tone:#0284c7}[data-color="cyan"]{--forum-tone:#0891b2}[data-color="steel"]{--forum-tone:#4a6a8a}[data-color="teal"]{--forum-tone:#0f8a7e}[data-color="emerald"]{--forum-tone:#059669}[data-color="mint"]{--forum-tone:#2e9e7a}[data-color="green"]{--forum-tone:#2e7d32}[data-color="forest"]{--forum-tone:#1b5e20}[data-color="lime"]{--forum-tone:#5c8a00}[data-color="olive"]{--forum-tone:#6b6b1f}[data-color="yellow"]{--forum-tone:#a68a00}[data-color="gold"]{--forum-tone:#b8860b}[data-color="amber"]{--forum-tone:#c77700}[data-color="sand"]{--forum-tone:#9c7a4a}[data-color="orange"]{--forum-tone:#e65100}[data-color="tangerine"]{--forum-tone:#f4511e}[data-color="coral"]{--forum-tone:#e2574c}[data-color="peach"]{--forum-tone:#c8693f}[data-color="brown"]{--forum-tone:#7b4a2a}[data-color="slate"]{--forum-tone:#475569}[data-color="gray"]{--forum-tone:#6b7280}[data-color="zinc"]{--forum-tone:#52525b}[data-color="silver"]{--forum-tone:#8a94a0}[data-color="muted"]{--forum-tone:#8493a5}[data-color="ink"]{--forum-tone:#111827}
        :root[data-forum-scheme="dark"] [data-color="red"]{--forum-tone:#ff6b6b}:root[data-forum-scheme="dark"] [data-color="crimson"]{--forum-tone:#ff5c7c}:root[data-forum-scheme="dark"] [data-color="wine"]{--forum-tone:#e88aa8}:root[data-forum-scheme="dark"] [data-color="maroon"]{--forum-tone:#f19a9a}:root[data-forum-scheme="dark"] [data-color="rose"]{--forum-tone:#ff8fb1}:root[data-forum-scheme="dark"] [data-color="pink"]{--forum-tone:#ff8cc6}:root[data-forum-scheme="dark"] [data-color="fuchsia"]{--forum-tone:#ef7bff}:root[data-forum-scheme="dark"] [data-color="magenta"]{--forum-tone:#e879f9}:root[data-forum-scheme="dark"] [data-color="plum"]{--forum-tone:#d6a2e8}:root[data-forum-scheme="dark"] [data-color="purple"]{--forum-tone:#b794ff}:root[data-forum-scheme="dark"] [data-color="violet"]{--forum-tone:#a99bff}:root[data-forum-scheme="dark"] [data-color="indigo"]{--forum-tone:#8c9eff}:root[data-forum-scheme="dark"] [data-color="navy"]{--forum-tone:#7aa2ff}:root[data-forum-scheme="dark"] [data-color="blue"]{--forum-tone:#6cb2ff}:root[data-forum-scheme="dark"] [data-color="azure"]{--forum-tone:#5ac8fa}:root[data-forum-scheme="dark"] [data-color="sky"]{--forum-tone:#7dd3fc}:root[data-forum-scheme="dark"] [data-color="cyan"]{--forum-tone:#67e8f9}:root[data-forum-scheme="dark"] [data-color="steel"]{--forum-tone:#9fbad6}:root[data-forum-scheme="dark"] [data-color="teal"]{--forum-tone:#5eead4}:root[data-forum-scheme="dark"] [data-color="emerald"]{--forum-tone:#6ee7b7}:root[data-forum-scheme="dark"] [data-color="mint"]{--forum-tone:#9be8c8}:root[data-forum-scheme="dark"] [data-color="green"]{--forum-tone:#81c784}:root[data-forum-scheme="dark"] [data-color="forest"]{--forum-tone:#66bb6a}:root[data-forum-scheme="dark"] [data-color="lime"]{--forum-tone:#bef264}:root[data-forum-scheme="dark"] [data-color="olive"]{--forum-tone:#d4d48a}:root[data-forum-scheme="dark"] [data-color="yellow"]{--forum-tone:#fde047}:root[data-forum-scheme="dark"] [data-color="gold"]{--forum-tone:#f5c96a}:root[data-forum-scheme="dark"] [data-color="amber"]{--forum-tone:#fbbf24}:root[data-forum-scheme="dark"] [data-color="sand"]{--forum-tone:#e3c79c}:root[data-forum-scheme="dark"] [data-color="orange"]{--forum-tone:#ffa057}:root[data-forum-scheme="dark"] [data-color="tangerine"]{--forum-tone:#ff8a65}:root[data-forum-scheme="dark"] [data-color="coral"]{--forum-tone:#ff8f7f}:root[data-forum-scheme="dark"] [data-color="peach"]{--forum-tone:#ffb38a}:root[data-forum-scheme="dark"] [data-color="brown"]{--forum-tone:#d7a07c}:root[data-forum-scheme="dark"] [data-color="slate"]{--forum-tone:#a8b5c8}:root[data-forum-scheme="dark"] [data-color="gray"]{--forum-tone:#b8bec8}:root[data-forum-scheme="dark"] [data-color="zinc"]{--forum-tone:#c4c4cc}:root[data-forum-scheme="dark"] [data-color="silver"]{--forum-tone:#d5dbe3}:root[data-forum-scheme="dark"] [data-color="muted"]{--forum-tone:#8fa3b8}:root[data-forum-scheme="dark"] [data-color="ink"]{--forum-tone:#f3f4f6}
        :is(.forum-post-rich,.forum-editor-canvas) span[data-color]{color:var(--forum-tone)}
        :is(.forum-post-rich,.forum-editor-canvas .tiptap) [data-align="center"]{text-align:center}
        :is(.forum-post-rich,.forum-editor-canvas .tiptap) [data-align="right"]{text-align:right}
        :is(.forum-post-rich,.forum-editor-canvas .tiptap) [data-align="justify"]{text-align:justify}
        /* Images by link: width in % of the column, never larger than the image itself. */
        :is(.forum-post-rich,.forum-editor-canvas .tiptap) figure{margin:12px 0;max-width:100%}
        :is(.forum-post-rich,.forum-editor-canvas .tiptap) figure img{display:block;max-width:100%;height:auto;border-radius:8px}
        :is(.forum-post-rich,.forum-editor-canvas .tiptap) figure[data-align="center"]{margin-left:auto;margin-right:auto}
        :is(.forum-post-rich,.forum-editor-canvas .tiptap) figure[data-align="center"] img{margin:0 auto}
        :is(.forum-post-rich,.forum-editor-canvas .tiptap) figure[data-align="right"]{margin-left:auto}
        :is(.forum-post-rich,.forum-editor-canvas .tiptap) figure[data-align="right"] img{margin-left:auto}
        .forum-post-rich figure[data-width="10"],.forum-editor-canvas .link-image-view[data-width="10"]{width:10%}.forum-post-rich figure[data-width="15"],.forum-editor-canvas .link-image-view[data-width="15"]{width:15%}.forum-post-rich figure[data-width="20"],.forum-editor-canvas .link-image-view[data-width="20"]{width:20%}.forum-post-rich figure[data-width="25"],.forum-editor-canvas .link-image-view[data-width="25"]{width:25%}.forum-post-rich figure[data-width="30"],.forum-editor-canvas .link-image-view[data-width="30"]{width:30%}.forum-post-rich figure[data-width="35"],.forum-editor-canvas .link-image-view[data-width="35"]{width:35%}.forum-post-rich figure[data-width="40"],.forum-editor-canvas .link-image-view[data-width="40"]{width:40%}.forum-post-rich figure[data-width="45"],.forum-editor-canvas .link-image-view[data-width="45"]{width:45%}.forum-post-rich figure[data-width="50"],.forum-editor-canvas .link-image-view[data-width="50"]{width:50%}.forum-post-rich figure[data-width="55"],.forum-editor-canvas .link-image-view[data-width="55"]{width:55%}.forum-post-rich figure[data-width="60"],.forum-editor-canvas .link-image-view[data-width="60"]{width:60%}.forum-post-rich figure[data-width="65"],.forum-editor-canvas .link-image-view[data-width="65"]{width:65%}.forum-post-rich figure[data-width="70"],.forum-editor-canvas .link-image-view[data-width="70"]{width:70%}.forum-post-rich figure[data-width="75"],.forum-editor-canvas .link-image-view[data-width="75"]{width:75%}.forum-post-rich figure[data-width="80"],.forum-editor-canvas .link-image-view[data-width="80"]{width:80%}.forum-post-rich figure[data-width="85"],.forum-editor-canvas .link-image-view[data-width="85"]{width:85%}.forum-post-rich figure[data-width="90"],.forum-editor-canvas .link-image-view[data-width="90"]{width:90%}.forum-post-rich figure[data-width="95"],.forum-editor-canvas .link-image-view[data-width="95"]{width:95%}
        .forum-post-rich figure img{width:auto}
        .forum-editor-canvas .link-image-view{width:100%}
        .forum-editor-canvas .link-image-view[data-align="center"]{text-align:center}
        .forum-editor-canvas .link-image-view[data-align="right"]{text-align:right}
        .link-image-frame{position:relative;display:inline-block;max-width:100%;vertical-align:top}
        .forum-editor-canvas .link-image-view img{margin:0}
        .forum-editor-canvas .link-image-view.ProseMirror-selectednode img{outline:3px solid #739cc4;outline-offset:2px}
        .link-image-handle{position:absolute;right:-6px;bottom:-6px;width:16px;height:16px;border:2px solid #fff;border-radius:50%;background:#355b85;box-shadow:0 1px 4px rgba(0,0,0,.3);cursor:nwse-resize;opacity:0;touch-action:none;transition:opacity .15s}
        .link-image-view:hover .link-image-handle,.link-image-view.ProseMirror-selectednode .link-image-handle{opacity:1}
        .forum-image-preview{display:grid;place-items:center;min-height:120px;margin:12px 0;border:1px dashed #ced8e3;border-radius:8px;background:#f8fafc;overflow:hidden}
        .forum-image-preview img{max-width:100%;max-height:220px;object-fit:contain}
        .forum-image-preview:empty::before{content:attr(data-empty);color:#8a9aac;font-size:13px}
        .forum-image-preview[data-state="error"] img{display:none}
        .forum-image-preview[data-state="error"]::before{content:attr(data-error);color:#ad423b;font-size:13px}
        .forum-image-preview[data-state="loading"]::before{content:attr(data-loading);color:#8a9aac;font-size:13px}
        .forum-image-preview[data-state="blocked"]::before{content:attr(data-blocked);max-width:90%;color:#ad423b;font-size:13px;text-align:center}
        :root[data-forum-scheme="dark"] .forum-image-preview{border-color:#3a4e63;background:#121b26}
        /* Video embeds: players rendered by the forum from a provider + id token. */
        .forum-media{position:relative;width:100%;max-width:720px;aspect-ratio:16/9;margin:12px 0;border-radius:10px;overflow:hidden;background:#0d1117}
        .forum-media[data-align="center"]{margin-left:auto;margin-right:auto}
        .forum-media[data-align="right"]{margin-left:auto}
        .forum-media iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
        .forum-editor-canvas .tiptap .media-embed-card{position:relative;display:grid;place-items:center;width:100%;max-width:420px;aspect-ratio:16/9;margin:12px 0;border-radius:10px;overflow:hidden;background:#0d1117;color:#fff}
        .forum-editor-canvas .tiptap .media-embed-card[data-align="center"]{margin-left:auto;margin-right:auto}
        .forum-editor-canvas .tiptap .media-embed-card[data-align="right"]{margin-left:auto}
        .forum-editor-canvas .tiptap .media-embed-twitch{background:linear-gradient(135deg,#6441a5,#2b1650)}
        .forum-editor-canvas .tiptap .media-embed-card img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.75}
        .media-embed-label{position:relative;padding:6px 12px;border-radius:999px;background:rgba(0,0,0,.6);font-size:13px;font-weight:600}
        .forum-editor-canvas .tiptap .media-embed-card.ProseMirror-selectednode{outline:3px solid #739cc4;outline-offset:2px}
        .forum-media-sites{margin:12px 0 0;color:#536982;font-size:13px}
        .forum-media-sites span{display:block;margin-bottom:3px;color:#8a9aac}
        :root[data-forum-scheme="dark"] .forum-media-sites{color:#c9d9ee}
        /* Allowed sites in the insert dialogs: open the upload site in a new tab, with a hint. */
        .forum-media-sites{display:flex;flex-wrap:wrap;align-items:center;gap:6px 8px}
        .forum-media-sites span{flex-basis:100%;margin:0}
        .forum-site-link-out{position:relative;display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border:1px solid #d9e1eb;border-radius:999px;background:#f8fafc;color:#355b85;font-size:13px;font-weight:600;text-decoration:none!important}
        .forum-site-link-out:hover,.forum-site-link-out:focus-visible{border-color:#9db6cf;background:#eef4fa}
        .forum-site-link-out svg{opacity:.7}
        .forum-site-link-out::after{content:attr(data-tooltip);position:absolute;left:50%;bottom:calc(100% + 7px);z-index:5;padding:4px 8px;border-radius:6px;background:#27374b;color:#fff;font-size:12px;font-weight:500;white-space:nowrap;pointer-events:none;opacity:0;transform:translate(-50%,3px);transition:opacity .15s ease,transform .15s ease}
        .forum-site-link-out:hover::after,.forum-site-link-out:focus-visible::after{opacity:1;transform:translate(-50%,0)}
        @media(prefers-reduced-motion:reduce){.forum-site-link-out::after{transition:none}}
        :root[data-forum-scheme="dark"] .forum-site-link-out{border-color:#37495d;background:#121b26;color:#a3c6ec}
        :root[data-forum-scheme="dark"] .forum-site-link-out:hover{background:#1d2a37}
        :root[data-forum-scheme="dark"] .forum-site-link-out::after{background:#e2e8f0;color:#151f2a}
        .forum-topic-moderation-note{margin:10px 0 0;color:#7b8ca0;font-size:13px}
        /* Latest activity table on the forum home. */
        .forum-activity{margin:0 0 22px}
        .forum-activity-tabs{display:flex;gap:2px;overflow-x:auto;padding:0 10px;border-bottom:1px solid #e2e8f0;background:#f4f7fb;scrollbar-width:none}
        .forum-activity-tabs a{position:relative;flex:none;padding:13px 14px;color:#4a6078;font-size:14px;font-weight:600;text-decoration:none!important;white-space:nowrap}
        .forum-activity-tabs a:hover{color:#27374b}
        .forum-activity-tabs a[aria-selected="true"]{color:#27374b}
        .forum-activity-tabs a[aria-selected="true"]::after{content:"";position:absolute;left:10px;right:10px;bottom:-1px;height:3px;border-radius:3px 3px 0 0;background:#2f6fd6}
        .forum-activity-row{display:grid;grid-template-columns:minmax(0,1fr) auto 150px 120px 130px;align-items:center;gap:12px;padding:10px 20px;border-bottom:1px solid #edf0f3;font-size:14px}
        .forum-activity-row:last-child{border-bottom:0}
        .forum-activity-row:hover{background:#f8fafc}
        .forum-activity-title{overflow:hidden;font-weight:650;text-overflow:ellipsis;white-space:nowrap}
        .forum-activity-marks{display:flex;gap:6px;color:#8a9aac}
        .forum-activity-section,.forum-activity-time{overflow:hidden;color:#7b8ca0;font-size:13px;text-overflow:ellipsis;white-space:nowrap}
        .forum-activity-author{overflow:hidden;color:#4a6078;font-size:13px;font-weight:600;text-align:right;text-overflow:ellipsis;white-space:nowrap}
        .forum-activity-empty{margin:0;padding:22px 20px;color:#8a9aac;font-size:14px}
        @media(max-width:900px){.forum-activity-row{grid-template-columns:minmax(0,1fr) auto 120px 110px}.forum-activity-section{display:none}}
        @media(max-width:650px){.forum-activity-row{grid-template-columns:minmax(0,1fr) auto;gap:3px 10px;padding:11px 16px}.forum-activity-title{white-space:normal;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}.forum-activity-time{grid-column:1;grid-row:2}.forum-activity-author{grid-column:2;grid-row:2}}
        :root[data-forum-scheme="dark"] .forum-activity-tabs{border-color:#2a3949;background:#192634}
        :root[data-forum-scheme="dark"] .forum-activity-tabs a{color:#9fb2c7}
        :root[data-forum-scheme="dark"] .forum-activity-tabs a:hover,
        :root[data-forum-scheme="dark"] .forum-activity-tabs a[aria-selected="true"]{color:#eef3f8}
        :root[data-forum-scheme="dark"] .forum-activity-tabs a[aria-selected="true"]::after{background:#5aa0f0}
        :root[data-forum-scheme="dark"] .forum-activity-row{border-color:#223040}
        :root[data-forum-scheme="dark"] .forum-activity-row:hover{background:#1a2532}
        :root[data-forum-scheme="dark"] .forum-activity-author{color:#c9d9ee}
        /* Light/dark switch, the same control as the player account (sun and moon). */
        .forum-scheme-toggle{display:grid;place-items:center;width:44px;height:38px;padding:0;border:0;background:transparent;cursor:pointer}
        .forum-scheme-track{position:relative;display:block;width:40px;height:22px;border:1px solid #d9e1eb;border-radius:11px;background:#f3f6fa;transition:border-color .25s ease}
        .forum-scheme-toggle:hover .forum-scheme-track{border-color:#9db6cf}
        .forum-scheme-toggle:focus-visible{outline:2px solid #6396cd;outline-offset:2px;border-radius:12px}
        .forum-scheme-thumb{position:absolute;top:1px;left:1px;width:18px;height:18px;border-radius:50%;background:#fff;color:#c58a1c;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:transform .25s ease}
        .forum-scheme-thumb svg{position:absolute;top:3px;left:3px;width:12px;height:12px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;transition:opacity .25s ease}
        .forum-scheme-moon{opacity:0}
        :root[data-forum-scheme="dark"] .forum-scheme-track{border-color:#324252;background:#1d2a37}
        :root[data-forum-scheme="dark"] .forum-scheme-thumb{transform:translateX(18px);background:#2e4057;color:#e8d38a}
        :root[data-forum-scheme="dark"] .forum-scheme-sun{opacity:0}
        :root[data-forum-scheme="dark"] .forum-scheme-moon{opacity:1}
        .forum-theme-reveal::view-transition-old(root),.forum-theme-reveal::view-transition-new(root){animation:none;mix-blend-mode:normal}
        .forum-theme-reveal::view-transition-old(root){z-index:1}
        .forum-theme-reveal::view-transition-new(root){z-index:2}
        .forum-theme-reveal *,.forum-theme-reveal *::before,.forum-theme-reveal *::after{transition:none!important}
        @media(prefers-reduced-motion:reduce){.forum-scheme-track,.forum-scheme-thumb,.forum-scheme-thumb svg{transition:none}}
        @media(max-width:430px){.forum-brand-copy{display:none}.forum-actions{gap:6px}}
        /* The bell: unread badge, a gentle ring when something new arrives, a dropdown list. */
        .forum-bell{position:relative}
        .forum-bell-button{position:relative;display:grid;place-items:center;width:38px;height:38px;border:1px solid #e1e7ef;border-radius:50%;color:#49617c;list-style:none;cursor:pointer}
        .forum-bell-button::-webkit-details-marker{display:none}
        .forum-bell-button:hover,.forum-bell[open]>.forum-bell-button{background:#f5f8fc}
        .forum-bell.has-unread .forum-bell-button{color:#27374b}
        .forum-bell-count{position:absolute;top:-4px;right:-5px;min-width:18px;height:18px;padding:0 5px;border:2px solid #fff;border-radius:999px;background:#d64545;color:#fff;font-size:10px;font-weight:700;line-height:14px;text-align:center}
        @keyframes forum-bell-ring{0%,100%{transform:rotate(0)}15%{transform:rotate(14deg)}30%{transform:rotate(-12deg)}45%{transform:rotate(9deg)}60%{transform:rotate(-6deg)}75%{transform:rotate(3deg)}}
        .forum-bell.is-ringing .forum-bell-icon{transform-origin:50% 8%;animation:forum-bell-ring 1s ease-in-out 2}
        .forum-bell-panel{position:absolute;right:0;top:calc(100% + 8px);z-index:60;width:380px;max-width:calc(100vw - 26px);max-height:460px;overflow:auto;padding:0;border:1px solid #dfe5ed;border-radius:12px;background:#fff;box-shadow:0 12px 32px rgba(23,37,56,.14);overscroll-behavior:contain}
        .forum-bell[open]>.forum-bell-panel{transform-origin:top right;animation:forum-mod-pop .28s cubic-bezier(.16,.84,.3,1) both}
        .forum-bell-head{position:sticky;top:0;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:13px 16px;border-bottom:1px solid #edf0f3;background:inherit;color:#27374b;font-size:16px}
        .forum-bell-head form{margin:0}
        .forum-bell-head button{padding:0;border:0;background:none;color:#355b85;font:inherit;font-size:13px;cursor:pointer}
        .forum-bell-panel>form{margin:0}
        .forum-bell-item{display:grid;grid-template-columns:36px minmax(0,1fr) 10px;align-items:start;gap:12px;width:100%;padding:12px 16px;border:0;border-bottom:1px solid #edf0f3;background:transparent;color:#405b79;font:inherit;font-size:14px;line-height:1.45;text-align:left;cursor:pointer}
        .forum-bell-panel>form:last-child .forum-bell-item{border-bottom:0}
        .forum-bell-item:hover{background:#f5f8fc}
        .forum-bell-item.is-unread{background:#f3f7fd}
        .forum-bell-item.is-unread:hover{background:#eaf1fb}
        .forum-bell-item .forum-bell-avatar{width:36px;height:36px;border-radius:8px;font-size:15px;font-weight:700;color:#405b79}
        .forum-bell-item-copy{display:grid;gap:4px;min-width:0;overflow-wrap:anywhere}
        .forum-bell-item .notification-name{color:#2f6fd6;font-weight:600}
        .forum-bell-item .notification-topic{color:#2f6fd6}
        .forum-bell-item small{color:#8a9aac;font-size:12px}
        .forum-bell-dot{align-self:center;width:9px;height:9px;border-radius:50%;background:#2f6fd6;box-shadow:0 0 0 3px rgba(47,111,214,.15)}
        .forum-bell-empty{margin:0;padding:18px 10px;color:#8a9aac;font-size:14px;text-align:center}
        @media(prefers-reduced-motion:reduce){.forum-bell.is-ringing .forum-bell-icon,.forum-bell[open]>.forum-bell-panel{animation:none}}
        @media(max-width:760px){.forum-bell-panel{position:fixed;left:13px;right:13px;top:auto;width:auto;max-width:none;max-height:70vh}}
        :root[data-forum-scheme="dark"] .forum-bell-button{border-color:#324252;color:#c9d9ee}
        :root[data-forum-scheme="dark"] .forum-bell-button:hover,:root[data-forum-scheme="dark"] .forum-bell[open]>.forum-bell-button{background:#1d2a37}
        :root[data-forum-scheme="dark"] .forum-bell-count{border-color:#151f2a}
        :root[data-forum-scheme="dark"] .forum-bell-panel{border-color:#2a3949;background:#151f2a;box-shadow:0 18px 48px rgba(0,0,0,.5)}
        :root[data-forum-scheme="dark"] .forum-bell-head{border-color:#263445;color:#eef3f8}
        :root[data-forum-scheme="dark"] .forum-bell-head button{color:#a3c6ec}
        :root[data-forum-scheme="dark"] .forum-bell-item{border-color:#223040;color:#c3d2e2}
        :root[data-forum-scheme="dark"] .forum-bell-item:hover{background:#1d2a37}
        :root[data-forum-scheme="dark"] .forum-bell-item.is-unread{background:#182636}
        :root[data-forum-scheme="dark"] .forum-bell-item.is-unread:hover{background:#1f3044}
        :root[data-forum-scheme="dark"] .forum-bell-item .notification-name,
        :root[data-forum-scheme="dark"] .forum-bell-item .notification-topic{color:#7fb4f0}
        :root[data-forum-scheme="dark"] .forum-bell-dot{background:#5aa0f0;box-shadow:0 0 0 3px rgba(90,160,240,.2)}
        .forum-trash-topic{display:grid;gap:2px;min-width:0}
        .forum-trash-topic small{color:#7b8ca0;font-size:12px}
        .forum-editor-menu{position:relative}
        .forum-editor-menu>summary{display:inline-flex;align-items:center;justify-content:center;height:30px;min-width:32px;padding:3px 7px;border:1px solid transparent;border-radius:6px;color:#49617c;list-style:none;cursor:pointer}
        .forum-editor-menu>summary::-webkit-details-marker{display:none}
        .forum-editor-menu>summary:hover,.forum-editor-menu[open]>summary{background:#e8eef5;border-color:#d5e0ec}
        .forum-editor-menu>summary:focus-visible{outline:2px solid #739cc4;outline-offset:1px}
        .forum-color-bar{stroke:#d32f2f}
        .forum-editor-menu-panel{position:absolute;left:0;top:calc(100% + 6px);z-index:40;display:grid;gap:5px;padding:9px;border:1px solid #dce4ee;border-radius:10px;background:#fff;box-shadow:0 14px 36px rgba(23,37,56,.16)}
        .forum-color-grid{grid-template-columns:repeat(8,24px)}
        .forum-editor-toolbar .forum-color-grid button{width:24px;min-width:0;height:24px;padding:0;border:1px solid rgba(0,0,0,.12);border-radius:5px}
        .forum-editor-toolbar .forum-color-swatch{background:var(--forum-tone)}
        .forum-editor-toolbar .forum-color-swatch:hover{transform:scale(1.12);border-color:rgba(0,0,0,.35)}
        .forum-editor-toolbar .forum-color-reset{grid-column:span 8;width:100%!important;display:grid;place-items:center;background:#f3f6fa}
        .forum-emoji-grid{grid-template-columns:repeat(8,32px);gap:2px}
        .forum-editor-toolbar .forum-emoji-grid button{width:32px;min-width:0;height:32px;padding:0;font-size:19px;line-height:1}
        .forum-editor-link-dialog label+label{margin-top:12px}
        .forum-editor-link-dialog small{color:#8a9aac;font-size:12px}
        [dir="rtl"] .forum-editor-menu-panel{left:auto;right:0}
        @media(max-width:480px){.forum-editor-menu{position:static}.forum-editor-toolbar{position:relative}.forum-editor-menu-panel{left:8px;right:8px;justify-content:center}}
        :root[data-forum-scheme="dark"] .forum-editor-menu>summary{color:#d7e4f2}
        :root[data-forum-scheme="dark"] .forum-editor-menu>summary:hover,
        :root[data-forum-scheme="dark"] .forum-editor-menu[open]>summary{background:#31445c;border-color:#47627e}
        :root[data-forum-scheme="dark"] .forum-editor-menu-panel{border-color:#37495d;background:#172332;box-shadow:0 18px 48px rgba(0,0,0,.5)}
        :root[data-forum-scheme="dark"] .forum-editor-toolbar .forum-color-grid button{border-color:rgba(255,255,255,.14)}
        :root[data-forum-scheme="dark"] .forum-editor-toolbar .forum-color-reset{background:#202e3f;color:#d7e4f2}
        :root[data-forum-scheme="dark"] .forum-editor-link-dialog small{color:#8fa3b8}

        /* Reusable forum controls follow the same spacing and typography. */
        .forum-skip-link{position:absolute;left:12px;top:-80px;z-index:100;padding:9px 14px;background:#fff;color:#27374b;border:2px solid #739cc4;border-radius:8px}
        .forum-skip-link:focus-visible{top:12px}
        :where(.forum-body,.forum-top) :where(a,button,input,textarea,select,summary):focus-visible{outline:2px solid #6396cd;outline-offset:3px}
        .forum-breadcrumb{flex-wrap:wrap;overflow-wrap:anywhere}
        .forum-topic-main{min-width:0;flex:1}
        /* Search sits beside the page heading instead of a separate header tab. */
        .forum-heading-search{align-items:flex-end;margin-bottom:26px}
        .forum-heading-copy{min-width:0}
        .forum-heading-search .forum-desc{margin:8px 0 0}
        .forum-heading-side{display:flex;align-items:center;justify-content:flex-end;flex-wrap:wrap;gap:10px;margin-left:auto}
        .forum-quick-search{position:relative;width:300px;max-width:100%}
        .forum-quick-search input{width:100%;height:40px;padding:0 14px 0 40px;border:1px solid #d9e1eb;border-radius:999px;background:#fff;color:#27374b;font:inherit;font-size:14px;transition:border-color .2s ease,box-shadow .2s ease}
        .forum-quick-search input::placeholder{color:#8a9aac}
        .forum-quick-search input:hover{border-color:#c3cfdc}
        .forum-quick-search input:focus{border-color:#739cc4;box-shadow:0 0 0 4px rgba(99,150,205,.16);outline:none}
        .forum-quick-search button{position:absolute;left:4px;top:4px;width:32px;height:32px;display:grid;place-items:center;padding:0;border:0;border-radius:50%;background:transparent;color:#7b8ca0;cursor:pointer}
        .forum-quick-search button:hover{color:#355b85}
        [dir="rtl"] .forum-quick-search input{padding:0 40px 0 14px}
        [dir="rtl"] .forum-quick-search button{left:auto;right:4px}
        .forum-inline-confirm{position:relative;min-width:0}
        .forum-inline-confirm summary{list-style:none;width:max-content;max-width:100%;user-select:none}
        .forum-inline-confirm summary::-webkit-details-marker{display:none}
        .forum-confirm-content{display:grid;justify-items:start;gap:10px;max-width:330px;margin-top:8px;padding:12px 14px;border:1px solid #edc4c4;border-radius:9px;background:#fffafa;color:#823b3b;font-size:13px}
        .forum-confirm-content p{margin:0;line-height:1.5}
        .forum-section-row:focus-within,.forum-topic-row:focus-within{background:rgba(53,91,133,.035)}
        .forum-topic-row .forum-tag{display:inline-block;margin:0 0 0 7px;vertical-align:middle}
        .forum-footer{padding:0 16px 24px}
        .forum-footer a{color:inherit}
        @media(max-width:650px){
            .forum-brand-wide img{max-width:132px;max-height:40px}
            .forum-heading-search{align-items:stretch}
            .forum-heading-side{width:100%;justify-content:flex-start;margin-left:0}
            .forum-quick-search{width:100%}
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
        :root[data-forum-scheme="dark"] .forum-site-link{color:#a9bdd3}
        :root[data-forum-scheme="dark"] .forum-quick-search input:hover{border-color:#4a6178}
        :root[data-forum-scheme="dark"] .forum-quick-search input:focus{border-color:#6f9bd0;box-shadow:0 0 0 4px rgba(111,155,208,.2)}
        :root[data-forum-scheme="dark"] .forum-quick-search input::placeholder,
        :root[data-forum-scheme="dark"] .forum-quick-search button{color:#8fa3b8}
        :root[data-forum-scheme="dark"] .forum-quick-search button:hover{color:#c9d9ee}
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
        :root[data-forum-theme="default"][data-forum-scheme="dark"] .language-menu>summary{padding:0 10px 0 12px;border-color:rgba(255,255,255,.18);border-radius:7px;background:rgba(0,0,0,.16);color:rgba(255,255,255,.86)}
        :root[data-forum-theme="default"][data-forum-scheme="dark"] .language-menu>summary:hover,
        :root[data-forum-theme="default"][data-forum-scheme="dark"] .language-menu[open]>summary{border-color:#b27a2d}
        :root[data-forum-theme="default"][data-forum-scheme="dark"] .language-menu>summary:focus-visible{outline-color:#b27a2d}
        :root[data-forum-theme="default"][data-forum-scheme="dark"] .language-menu-chevron{color:rgba(255,255,255,.6);transition-duration:.16s}
        :root[data-forum-theme="default"][data-forum-scheme="dark"] .language-menu-list{border-color:#343a3d;border-radius:8px;background:#0d1214;box-shadow:0 18px 50px rgba(0,0,0,.45)}
        :root[data-forum-theme="default"][data-forum-scheme="dark"] .language-menu[open]>.language-menu-list,
        :root[data-forum-theme="default"][data-forum-scheme="dark"] .language-menu[open]>.language-menu-list>a{animation:none}
        :root[data-forum-theme="default"][data-forum-scheme="dark"] .language-menu-list a{border-radius:5px;color:#d8d9d7}
        :root[data-forum-theme="default"][data-forum-scheme="dark"] .language-menu-list a small{color:#858b8d}
        :root[data-forum-theme="default"][data-forum-scheme="dark"] .language-menu-list a:hover{background:rgba(255,255,255,.06)}
        :root[data-forum-theme="default"][data-forum-scheme="dark"] .language-menu-list a.active{background:#b27a2d;color:#15110b}
        :root[data-forum-theme="default"][data-forum-scheme="dark"] .language-menu-list a.active small{color:rgba(21,17,11,.7)}
        :root[data-forum-scheme="dark"] .forum-identity{border-color:#324252;color:#e2e8f0}
        :root[data-forum-scheme="dark"] .forum-identity:hover,
        :root[data-forum-scheme="dark"] .forum-profile-menu[open]>.forum-identity{background:#1d2a37}
        :root[data-forum-scheme="dark"] .forum-profile-chevron,
        :root[data-forum-scheme="dark"] .forum-profile-head small,
        :root[data-forum-scheme="dark"] .forum-profile-balance small{color:#8fa3b8}
        :root[data-forum-scheme="dark"] .forum-profile-dropdown{border-color:#2a3949;background:#151f2a;box-shadow:0 18px 48px rgba(0,0,0,.5)}
        :root[data-forum-scheme="dark"] .forum-profile-head,
        :root[data-forum-scheme="dark"] .forum-profile-dropdown form{border-color:#263445;color:#eef3f8}
        :root[data-forum-scheme="dark"] .forum-profile-dropdown a,
        :root[data-forum-scheme="dark"] .forum-profile-dropdown button{color:#e2e8f0}
        :root[data-forum-scheme="dark"] .forum-profile-dropdown a:hover,
        :root[data-forum-scheme="dark"] .forum-profile-dropdown button:hover{background:#1d2a37}
        :root[data-forum-scheme="dark"] .forum-profile-dropdown .forum-profile-balance{border-color:#263445;background:#111a24}
        :root[data-forum-scheme="dark"] .forum-profile-balance strong{color:#e0b66d}
        :root[data-forum-scheme="dark"] .forum-identity-role{color:#8fa3b8}
        :root[data-forum-scheme="dark"] .forum-avatar{background:#2e4057;color:#c9d9ee}
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
        :root[data-forum-scheme="dark"] .forum-section-stats dd{color:#eef3f8}
        :root[data-forum-scheme="dark"] .forum-topic-counts dd{color:#eef3f8}
        :root[data-forum-scheme="dark"] .forum-topic-counts dt,
        :root[data-forum-scheme="dark"] .forum-topic-last-copy small{color:#8fa3b8}
        :root[data-forum-scheme="dark"] .forum-topic-author{color:#c9d9ee}
        :root[data-forum-scheme="dark"] .forum-topic-pages a{border-color:#324252;color:#a9bdd3}
        :root[data-forum-scheme="dark"] .forum-section-last-copy small span{color:#c9d9ee}
        :root[data-forum-scheme="dark"] .forum-panel-body,
        :root[data-forum-scheme="dark"] .forum-post-body{color:#dbe4ee}
        :root[data-forum-scheme="dark"] .forum-desc,
        :root[data-forum-scheme="dark"] .forum-help,
        :root[data-forum-scheme="dark"] .forum-post-date,
        :root[data-forum-scheme="dark"] .forum-topic-meta,
        :root[data-forum-scheme="dark"] .forum-topic-stats,
        :root[data-forum-scheme="dark"] .forum-section-stats dt,
        :root[data-forum-scheme="dark"] .forum-section-last-copy small,
        :root[data-forum-scheme="dark"] .forum-section-last-empty,
        :root[data-forum-scheme="dark"] .forum-section-main p,
        :root[data-forum-scheme="dark"] .forum-breadcrumb,
        :root[data-forum-scheme="dark"] .forum-actor-switch,
        :root[data-forum-scheme="dark"] .forum-post-edited,
        :root[data-forum-scheme="dark"] .forum-posting-as,
        :root[data-forum-scheme="dark"] .forum-footer{color:#9dabbc}
        :root[data-forum-scheme="dark"] .forum-form-grid label,
        :root[data-forum-scheme="dark"] .forum-compose-field,
        :root[data-forum-scheme="dark"] :where(.forum-form-grid,.forum-compose-field,.forum-quick-search) :where(input,textarea,select),
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
    <script src="{{ asset('assets/modules/forum/notifications.js') }}?v={{ cms_version() }}" defer></script>
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
        <div class="forum-actions">
            {{-- Players go to their account from the forum; a staff-only visit keeps the way to the site. --}}
            @if($actor->admin !== null && $actor->user === null)
                <a class="forum-site-link" href="{{ public_route('home') }}">{{ __('module-forum::messages.website') }}</a>
            @else
                <a class="forum-site-link" href="{{ public_route('account') }}">{{ __('module-forum::messages.account') }}</a>
            @endif
            <button type="button" class="forum-scheme-toggle" role="switch" aria-checked="false" data-forum-scheme-toggle
                    data-label-light="{{ __('Switch to the light theme') }}" data-label-dark="{{ __('Switch to the dark theme') }}"
                    aria-label="{{ __('Switch to the dark theme') }}" title="{{ __('Switch to the dark theme') }}">
                <span class="forum-scheme-track" aria-hidden="true"><span class="forum-scheme-thumb">
                    <svg class="forum-scheme-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3.5"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.65 17.65l1.42 1.42M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.65 6.35l1.42-1.42"/></svg>
                    <svg class="forum-scheme-moon" viewBox="0 0 24 24"><path d="M20.2 15.4A8.5 8.5 0 0 1 8.6 3.8 8.5 8.5 0 1 0 20.2 15.4Z"/></svg>
                </span></span>
            </button>
            <x-language-menu />
            @if($actor->user)
                @php($bellCenter = app(\App\Services\Notifications\PlayerNotificationCenter::class))
                @php($bellUnread = $bellCenter->unreadCount($actor->user))
                @php($bellItems = $bellCenter->latest($actor->user, 12))
                @php($bellActors = \App\Models\User::query()->whereIn('id', $bellItems->map(fn ($item) => (int) ($item->parameters['actor_id'] ?? 0))->filter()->unique())->get()->keyBy('id'))
                {{-- The player's bell: notifications from the forum (and later the account area). --}}
                <details @class(['forum-bell', 'has-unread' => $bellUnread > 0]) data-language-menu data-forum-bell
                         data-count-url="{{ route('notifications.count') }}" data-unread="{{ $bellUnread }}">
                    <summary class="forum-bell-button" aria-label="{{ __('module-forum::messages.notifications') }}: {{ $bellUnread }}" title="{{ __('module-forum::messages.notifications') }}">
                        <svg class="forum-bell-icon" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
                        <span class="forum-bell-count" data-forum-bell-count @if($bellUnread === 0) hidden @endif>{{ $bellUnread > 99 ? '99+' : $bellUnread }}</span>
                    </summary>
                    <div class="forum-bell-panel">
                        <div class="forum-bell-head">
                            <strong>{{ __('module-forum::messages.notifications') }}</strong>
                            @if($bellUnread > 0)
                                <form method="POST" action="{{ route('notifications.read-all') }}">
                                    @csrf
                                    <button type="submit">{{ __('module-forum::messages.notifications_read_all') }}</button>
                                </form>
                            @endif
                        </div>
                        @forelse($bellItems as $bellItem)
                            <form method="POST" action="{{ route('notifications.open', $bellItem->id) }}">
                                @csrf
                                @php($bellActor = $bellActors->get((int) ($bellItem->parameters['actor_id'] ?? 0)))
                                @php($bellName = (string) ($bellItem->parameters['name'] ?? '?'))
                                <button type="submit" @class(['forum-bell-item', 'is-unread' => $bellItem->read_at === null])>
                                    @if($bellActor)
                                        <x-account-avatar :user="$bellActor" :fallback="$bellName" class="forum-avatar forum-bell-avatar" />
                                    @else
                                        <span class="account-avatar forum-avatar forum-bell-avatar" aria-hidden="true"><span>{{ mb_strtoupper(mb_substr($bellName, 0, 1)) }}</span></span>
                                    @endif
                                    <span class="forum-bell-item-copy">
                                        <span class="forum-bell-item-text">{{ $bellCenter->html($bellItem) }}</span>
                                        <small>{{ \KaevCMS\Modules\Forum\Support\ForumFormat::when($bellItem->occurred_at) }}</small>
                                    </span>
                                    @if($bellItem->read_at === null)<span class="forum-bell-dot" aria-label="{{ __('module-forum::messages.notification_unread') }}"></span>@endif
                                </button>
                            </form>
                        @empty
                            <p class="forum-bell-empty">{{ __('module-forum::messages.notifications_empty') }}</p>
                        @endforelse
                    </div>
                </details>
            @endif
            @if($actor->isGuest())
                <a class="forum-button forum-button-primary" href="{{ public_route('login') }}">{{ __('module-forum::messages.login') }}</a>
            @else
                @php($adminPath = $actor->admin ? app(\App\Services\Admin\AdminPathSettings::class)->path() : null)
                @php($forumWallet = $actor->user ? app(\App\Services\Wallet\WalletAccountReadService::class)->forUser($actor->user) : null)
                {{-- Same menu as the player account: the chip opens a panel with the account links. --}}
                <details class="forum-profile-menu" data-language-menu data-forum-profile-menu>
                    <summary class="forum-identity" aria-label="{{ __('Open account menu') }}">
                        @if($actor->user)
                            <x-account-avatar :user="$actor->user" :fallback="$actor->user->publicName()" class="forum-avatar" />
                        @else
                            <span class="account-avatar forum-avatar" aria-hidden="true"><span>{{ mb_strtoupper(mb_substr($actor->name(), 0, 1)) }}</span></span>
                        @endif
                        <span class="forum-identity-copy">
                            <span class="forum-identity-name">{{ $actor->name() }}</span>
                            @if($actor->label())<span class="forum-identity-role">{{ $actor->label() }}</span>@endif
                        </span>
                        <svg class="forum-profile-chevron" width="12" height="12" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </summary>
                    <div class="forum-profile-dropdown">
                        <div class="forum-profile-head">
                            <strong>{{ $actor->name() }}</strong>
                            <small>{{ $actor->user?->email ?? $actor->admin?->email }}</small>
                        </div>
                        @if($actor->user)
                            <a class="forum-profile-balance" href="{{ public_route('wallet.index') }}" aria-label="{{ __('My wallet') }}: {{ $forumWallet['label'] }}">
                                <span class="forum-profile-balance-icon" aria-hidden="true"><x-account-wallet-icon :url="$forumWallet['icon_url']" /></span>
                                <span><small>{{ __('My wallet') }}</small><strong>{{ $forumWallet['label'] }}</strong></span>
                            </a>
                            <a href="{{ public_route('account') }}">{{ __('module-forum::messages.account') }}</a>
                            <a href="{{ public_route('profile.edit') }}">{{ __('Account settings') }}</a>
                            <a href="{{ public_route('security.edit') }}">{{ __('Security and password') }}</a>
                            <a href="{{ public_route('home') }}">{{ __('module-forum::messages.website') }}</a>
                            <form method="POST" action="{{ public_route('logout') }}">
                                @csrf
                                <button type="submit">{{ __('Sign out') }}</button>
                            </form>
                        @else
                            @if($actor->isOwner())
                                <a data-forum-owner-settings href="{{ route('admin.module-pages.forum.settings', ['adminPath' => $adminPath]) }}">{{ __('module-forum::messages.settings_link') }}</a>
                            @endif
                            <a href="{{ route('admin.dashboard', ['adminPath' => $adminPath]) }}">{{ __('Control panel') }}</a>
                            <form method="POST" action="{{ route('admin.logout', ['adminPath' => $adminPath]) }}">
                                @csrf
                                <button type="submit">{{ __('Sign out') }}</button>
                            </form>
                        @endif
                    </div>
                </details>
            @endif
        </div>
    </div>
</header>
<main class="forum-body" id="forum-main" tabindex="-1">
    @yield('content')
    @include('module-forum::partials.actor-switch')
</main>
<footer class="forum-footer"><a href="{{ public_route('home') }}">{{ site_name() }}</a> · {{ __('module-forum::messages.title') }}</footer>
</body>
</html>
