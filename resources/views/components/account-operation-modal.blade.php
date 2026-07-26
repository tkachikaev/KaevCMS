@props(['payload' => null])
@php
    $operation = is_array($payload) ? $payload : null;
    $type = in_array($operation['type'] ?? null, ['success', 'error', 'warning'], true) ? $operation['type'] : 'success';
    $items = is_array($operation['items'] ?? null) ? $operation['items'] : [];
@endphp
@if($operation)
<dialog
    class="account-operation-modal account-operation-modal-{{ $type }}"
    data-account-operation-modal
    data-account-operation-modal-auto-open
    aria-labelledby="account-operation-modal-title"
>
    <div class="account-operation-modal-card">
        <header class="account-operation-modal-head">
            <span class="account-operation-modal-mark" aria-hidden="true">{{ $type === 'success' ? '✓' : ($type === 'warning' ? '!' : '×') }}</span>
            <div>
                <span class="account-eyebrow">{{ $operation['eyebrow'] ?? __('Result') }}</span>
                <h2 id="account-operation-modal-title">{{ $operation['title'] ?? __('Result') }}</h2>
                @if(! empty($operation['message']))<p>{{ $operation['message'] }}</p>@endif
            </div>
            <button type="button" class="account-operation-modal-close" data-account-operation-modal-close aria-label="{{ __('Close') }}">×</button>
        </header>

        @if($items !== [])
            <div class="account-operation-rewards" aria-label="{{ __('Rewards') }}">
                @foreach($items as $item)
                    <article class="account-operation-reward">
                        <span class="account-operation-reward-icon" aria-hidden="true">
                            @if(! empty($item['icon_url']))
                                <img src="{{ $item['icon_url'] }}" alt="" width="64" height="64">
                            @else
                                <span>◇</span>
                            @endif
                        </span>
                        <strong>{{ $item['name'] ?? __('Unknown item') }}</strong>
                        <small>× {{ number_format((int) ($item['amount'] ?? 0), 0, '.', ' ') }}</small>
                    </article>
                @endforeach
            </div>
        @endif

        <footer class="account-operation-modal-actions">
            @if(! empty($operation['action_url']) && ! empty($operation['action_label']))
                <a wire:navigate class="account-button primary" href="{{ $operation['action_url'] }}">{{ $operation['action_label'] }}</a>
            @endif
            <button type="button" class="account-button secondary" data-account-operation-modal-close>{{ __('Close') }}</button>
        </footer>
    </div>
</dialog>
@endif
