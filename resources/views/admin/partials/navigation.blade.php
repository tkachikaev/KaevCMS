@php
    $admin = auth('admin')->user();
@endphp
<nav class="admin-menu" wire:navigate:scroll aria-label="{{ __('Administrator menu') }}">
    <a wire:navigate.hover wire:current.exact="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.dashboard') }}">
        <span>{{ __('Dashboard') }}</span>
    </a>

    @if($admin->hasPermission(\App\Auth\AdminPermission::ContentView))
        <details class="admin-menu-group" data-admin-menu-group="content" @if (request()->routeIs('admin.news.*', 'admin.pages.*')) open @endif>
            <summary class="admin-menu-group-summary">
                <span>{{ __('Content') }}</span>
                <span class="admin-menu-group-chevron" aria-hidden="true">⌄</span>
            </summary>
            <div class="admin-menu-group-items">
                <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.news.index') }}"><span>{{ __('News') }}</span></a>
                <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.pages.index') }}"><span>{{ __('Pages') }}</span></a>
            </div>
        </details>
    @endif

    @if($admin->hasPermission(\App\Auth\AdminPermission::AppearanceView))
        <details class="admin-menu-group" data-admin-menu-group="appearance" @if (request()->routeIs('admin.themes.*', 'admin.account-themes.*')) open @endif>
            <summary class="admin-menu-group-summary">
                <span>{{ __('Themes') }}</span>
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
                <span>{{ __('Servers') }}</span>
                <span class="admin-menu-group-chevron" aria-hidden="true">⌄</span>
            </summary>
            <div class="admin-menu-group-items">
                <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.settings.game-server') }}"><span>{{ __('Game servers') }}</span></a>
                <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.settings.login-server') }}"><span>{{ __('Login servers') }}</span></a>
            </div>
        </details>
    @endif

    @if($admin->hasPermission(\App\Auth\AdminPermission::UsersView) || $admin->hasPermission(\App\Auth\AdminPermission::AdministratorsView))
        <details class="admin-menu-group" data-admin-menu-group="users" @if (request()->routeIs('admin.users.*', 'admin.administrators.*')) open @endif>
            <summary class="admin-menu-group-summary">
                <span>{{ __('Users') }}</span>
                <span class="admin-menu-group-chevron" aria-hidden="true">⌄</span>
            </summary>
            <div class="admin-menu-group-items">
                @if($admin->hasPermission(\App\Auth\AdminPermission::UsersView))
                    <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.users.index') }}"><span>{{ __('Users') }}</span></a>
                @endif
                @if($admin->hasPermission(\App\Auth\AdminPermission::AdministratorsView))
                    <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.administrators.index') }}"><span>{{ __('Administrators') }}</span></a>
                @endif
            </div>
        </details>
    @endif

    @if($admin->hasPermission(\App\Auth\AdminPermission::MailView))
        <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.settings.mail') }}"><span>{{ __('Mail') }}</span></a>
    @endif

    @if($admin->hasPermission(\App\Auth\AdminPermission::SettingsView) || $admin->hasPermission(\App\Auth\AdminPermission::SystemView))
        <a
            wire:navigate.hover
            wire:current.exact="active"
            class="admin-menu-item" data-admin-menu-link
            data-admin-settings-link
            @if (request()->routeIs('admin.settings.general*', 'admin.settings.admin-panel*', 'admin.settings.registration*', 'admin.settings.game-accounts*', 'admin.settings.languages*', 'admin.settings.security*', 'admin.settings.system*')) data-current @endif
            href="{{ route('admin.settings.general') }}"
        ><span>{{ __('Settings') }}</span></a>
    @endif

    @if($admin->hasPermission(\App\Auth\AdminPermission::RewardsView))
        <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.rewards.index') }}"><span>{{ __('Reward queue') }}</span></a>
    @endif

    @if($admin->hasPermission(\App\Auth\AdminPermission::AuditView))
        <a wire:navigate.hover wire:current="active" class="admin-menu-item" data-admin-menu-link href="{{ route('admin.logs.index') }}"><span>{{ __('Audit log') }}</span></a>
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
                <span>{{ __('Modules') }}</span>
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
