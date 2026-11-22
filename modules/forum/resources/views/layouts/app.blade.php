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
    {{-- The core font stays here; everything else comes from the forum theme (Admin → Themes → Forum). --}}
    <style>
        @font-face{font-family:Inter;src:url("{{ asset('assets/admin/fonts/inter-variable.woff2') }}") format("woff2");font-display:swap}
    </style>
    <link rel="stylesheet" href="{{ app(\KaevCMS\Modules\Forum\Services\ForumThemes::class)->stylesheetUrl() }}" data-forum-theme-css>
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
