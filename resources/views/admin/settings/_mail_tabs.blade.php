<nav class="admin-tabs mail-template-tabs" aria-label="{{ __('Mail sections') }}">
    <a wire:navigate @class(['admin-tab', 'mail-template-tab', 'active' => request()->routeIs('admin.settings.mail')]) href="{{ route('admin.settings.mail') }}">
        {{ __('Connection') }}
    </a>

    <a wire:navigate @class(['admin-tab', 'mail-template-tab', 'active' => request()->routeIs('admin.settings.mail.delivery')]) href="{{ route('admin.settings.mail.delivery') }}">
        {{ __('Delivery') }}
    </a>

    @foreach ($mailTemplates as $templateKey => $item)
        <a wire:navigate
            @class([
                'admin-tab',
                'mail-template-tab',
                'active' => request()->routeIs('admin.settings.mail.template') && request()->route('template') === $templateKey,
            ])
            href="{{ route('admin.settings.mail.template', ['template' => $templateKey, 'locale' => $templateLocale ?? app()->getLocale()]) }}"
        >
            {{ $item['title'] }}
        </a>
    @endforeach

    <a wire:navigate @class(['admin-tab', 'mail-template-tab', 'active' => request()->routeIs('admin.settings.mail.custom') || request()->routeIs('admin.settings.mail.custom.send')]) href="{{ route('admin.settings.mail.custom') }}">
        {{ __('Send email') }}
    </a>
</nav>
