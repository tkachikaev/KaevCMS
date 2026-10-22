@php
    $admin = auth('admin')->user();
    // Thin line icons for the top-level entries; static, trusted markup.
    $menuIcon = static fn (string $path): string => '<svg class="admin-menu-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="'.$path.'"/></svg>';
    $menuIcons = [
        'dashboard' => $menuIcon('M3 11.5 12 4l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z'),
        'content' => $menuIcon('M6 3h9l3 3v15H6zM9 9h6M9 13h6M9 17h4'),
        'themes' => $menuIcon('M12 3a9 9 0 1 0 0 18c1 0 1.5-.8 1.5-1.6 0-1.2-1-1.6-1-2.6 0-1 .8-1.8 1.8-1.8H16a5 5 0 0 0 5-5c0-4-4-7-9-7zM7.5 11h.01M10 7.5h.01M14.5 7.5h.01'),
        'servers' => $menuIcon('M4 4h16v6H4zM4 14h16v6H4zM8 7h.01M8 17h.01'),
        'users' => $menuIcon('M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM3 20c0-3.3 2.7-6 6-6s6 2.7 6 6M16 4.5a3.5 3.5 0 0 1 0 7M18 14c2 .8 3 3 3 6'),
        'mail' => $menuIcon('M4 6h16v12H4zM4 7l8 6 8-6'),
        'settings' => $menuIcon('M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9 7 7M17 17l2.1 2.1M4.9 19.1 7 17M17 7l2.1-2.1'),
        'rewards' => $menuIcon('M4 11h16v9H4zM3 7h18v4H3zM12 7v13M12 7c-1.5-3-5-3-5-1s3 1 5 1zM12 7c1.5-3 5-3 5-1s-3 1-5 1z'),
        'audit' => $menuIcon('M6 3h12v18H6zM9 8h6M9 12h6M9 16h3'),
        'modules' => $menuIcon('M4 9h4.5a2 2 0 1 1 3 0H16v4.5a2 2 0 1 1 0 3V20H4zM16 9V4H4v5'),
    ];
@endphp<nav class="admin-menu" wire:navigate:scroll aria-label="{{ __('Administrator menu') }}">
    <a wire:navigate.hover wire:current.exact="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.dashboard') }}">
        <span>{!! $menuIcons['dashboard'] !!}{{ __('Dashboard') }}</span>
    </a>

    @if($admin->hasPermission(\App\Auth\AdminPermission::ContentView))
        <details class="admin-menu-group" data-admin-menu-group="content" @if (request()->routeIs('admin.news.*', 'admin.pages.*', 'admin.navigation.*')) open @endif>
            <summary class="admin-menu-group-summary">
                <span>{!! $menuIcons['content'] !!}{{ __('Content') }}</span>
                <span class="admin-menu-group-chevron" aria-hidden="true">⌄</span>
            </summary>
            <div class="admin-menu-group-items">
                <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.news.index') }}"><span>{{ __('News') }}</span></a>
                <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.pages.index') }}"><span>{{ __('Pages') }}</span></a>
                <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.navigation.index') }}"><span>{{ __('Navigation') }}</span></a>
            </div>
        </details>
    @endif

    @if($admin->hasPermission(\App\Auth\AdminPermission::AppearanceView))
        <details class="admin-menu-group" data-admin-menu-group="appearance" @if (request()->routeIs('admin.themes.*', 'admin.account-themes.*')) open @endif>
            <summary class="admin-menu-group-summary">
                <span>{!! $menuIcons['themes'] !!}{{ __('Themes') }}</span>
                <span class="admin-menu-group-chevron" aria-hidden="true">⌄</span>
            </summary>
            <div class="admin-menu-group-items">
                <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.themes.index') }}"><span>{{ __('Site') }}</span></a>
                <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.account-themes.index') }}"><span>{{ __('Account') }}</span></a>
            </div>
        </details>
    @endif

    @if($admin->hasPermission(\App\Auth\AdminPermission::ServersView))
        <details class="admin-menu-group" data-admin-menu-group="servers" @if (request()->routeIs('admin.settings.game-server*', 'admin.settings.login-server*')) open @endif>
            <summary class="admin-menu-group-summary">
                <span>{!! $menuIcons['servers'] !!}{{ __('Servers') }}</span>
                <span class="admin-menu-group-chevron" aria-hidden="true">⌄</span>
            </summary>
            <div class="admin-menu-group-items">
                <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.settings.game-server') }}"><span>{{ __('Game servers') }}</span></a>
                <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.settings.login-server') }}"><span>{{ __('Login servers') }}</span></a>
            </div>
        </details>
    @endif

    @if($admin->hasPermission(\App\Auth\AdminPermission::UsersView) || $admin->hasPermission(\App\Auth\AdminPermission::AdministratorsView) || $admin->hasPermission(\App\Auth\AdminPermission::WalletView))
        <details class="admin-menu-group" data-admin-menu-group="users" @if (request()->routeIs('admin.users.*', 'admin.administrators.*', 'admin.wallets.*')) open @endif>
            <summary class="admin-menu-group-summary">
                <span>{!! $menuIcons['users'] !!}{{ __('Users') }}</span>
                <span class="admin-menu-group-chevron" aria-hidden="true">⌄</span>
            </summary>
            <div class="admin-menu-group-items">
                @if($admin->hasPermission(\App\Auth\AdminPermission::UsersView))
                    <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.users.index') }}"><span>{{ __('Users') }}</span></a>
                @endif
                @if($admin->hasPermission(\App\Auth\AdminPermission::WalletView))
                    <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.wallets.index') }}"><span>{{ __('Wallets') }}</span></a>
                @endif
                @if($admin->hasPermission(\App\Auth\AdminPermission::AdministratorsView))
                    <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.administrators.index') }}"><span>{{ __('Administrators') }}</span></a>
                @endif
            </div>
        </details>
    @endif

    @if($admin->hasPermission(\App\Auth\AdminPermission::MailView))
        <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.settings.mail') }}"><span>{!! $menuIcons['mail'] !!}{{ __('Mail') }}</span></a>
    @endif

    @if($admin->hasPermission(\App\Auth\AdminPermission::SettingsView) || $admin->hasPermission(\App\Auth\AdminPermission::SystemView))
        <a
            wire:navigate.hover
            wire:current.exact="active"
            class="admin-menu-item" data-admin-menu-link
            data-admin-settings-link
            @if (request()->routeIs('admin.settings.general*', 'admin.settings.admin-panel*', 'admin.settings.notifications*', 'admin.settings.registration*', 'admin.settings.game-accounts*', 'admin.settings.languages*', 'admin.settings.security*', 'admin.settings.system*')) data-current @endif
            href="{{ route('admin.settings.general') }}"
        ><span>{!! $menuIcons['settings'] !!}{{ __('Settings') }}</span></a>
    @endif

    @if($admin->hasPermission(\App\Auth\AdminPermission::RewardsView))
        <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.rewards.index') }}"><span>{!! $menuIcons['rewards'] !!}{{ __('Reward queue') }}</span></a>
    @endif

    @if($admin->hasPermission(\App\Auth\AdminPermission::AuditView))
        <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.logs.index') }}"><span>{!! $menuIcons['audit'] !!}{{ __('Audit log') }}</span></a>
    @endif

    @php
        $moduleAdminLinks = app(\App\Support\Modules\ModuleNavigationRegistry::class)->availableAdminLinks(
            $admin,
            app(\App\Support\Modules\ModuleAdminAccessRegistry::class),
        );
    @endphp
    @if($admin->hasPermission(\App\Auth\AdminPermission::ModulesView) || $moduleAdminLinks !== [])
        <details class="admin-menu-group" data-admin-menu-group="modules" @if (request()->routeIs('admin.modules.*', 'admin.module-pages.*')) open @endif>
            <summary class="admin-menu-group-summary">
                <span>{!! $menuIcons['modules'] !!}{{ __('Modules') }}</span>
                <span class="admin-menu-group-chevron" aria-hidden="true">⌄</span>
            </summary>
            <div class="admin-menu-group-items">
                @if($admin->hasPermission(\App\Auth\AdminPermission::ModulesView))
                    <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.modules.index') }}"><span>{{ __('Modules') }}</span></a>
                @endif
                @foreach($moduleAdminLinks as $moduleLink)
                    <a
                        wire:navigate.hover
                        wire:current="active"
                        class="admin-menu-item" data-admin-menu-link
                        href="{{ route($moduleLink['route'], ['adminPath' => request()->route('adminPath')]) }}"
                        title="{{ __($moduleLink['description_key']) }}"
                        aria-label="{{ __($moduleLink['label_key']) }}"
                    ><span>{{ __($moduleLink['label_key']) }}</span>
                        @if($moduleLink['badge_enabled'] ?? false)
                            <livewire:admin.module-navigation-badge
                                :module-id="$moduleLink['module_id']"
                                :initial-count="$moduleLink['badge']"
                                :key="'admin-module-badge-'.$moduleLink['module_id']"
                            />
                        @endif
                    </a>
                @endforeach
            </div>
        </details>
    @endif
</nav>
