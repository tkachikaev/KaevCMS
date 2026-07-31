<span
    wire:poll.30s="refreshBadge"
    class="admin-menu-badge"
    data-module-admin-badge="{{ $moduleId }}"
    @if($count === 0) hidden @endif
    title="{{ __('Requires support response') }}"
    aria-label="{{ __('Requires support response: :count', ['count' => $count]) }}"
>{{ $count > 99 ? '99+' : $count }}</span>
