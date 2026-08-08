@props([
    'paginator',
    'ariaLabel' => null,
    'previousLabel' => null,
    'nextLabel' => null,
    'pageState' => null,
    'pagesLabel' => null,
    'numbered' => false,
    'window' => 2,
    'fragment' => null,
    'stateClass' => null,
])

@php
    $previousLabel ??= __('Back');
    $nextLabel ??= __('Next');
    $ariaLabel ??= __('Pagination');
    $pagesLabel ??= __('Pages');
    $pageState ??= __('Page :current of :last', [
        'current' => $paginator->currentPage(),
        'last' => $paginator->lastPage(),
    ]);

    $withFragment = static function (?string $url) use ($fragment): ?string {
        if ($url === null || $fragment === null || $fragment === '') {
            return $url;
        }

        return $url.'#'.ltrim($fragment, '#');
    };
@endphp

@if ($paginator->hasPages())
    <nav {{ $attributes->class('simple-pagination') }} aria-label="{{ $ariaLabel }}">
        @if ($paginator->onFirstPage())
            <span class="button button-secondary disabled">← {{ $previousLabel }}</span>
        @else
            <a wire:navigate class="button button-secondary" href="{{ $withFragment($paginator->previousPageUrl()) }}" rel="prev">← {{ $previousLabel }}</a>
        @endif

        @if ($numbered)
            @php
                $firstPage = max(1, $paginator->currentPage() - (int) $window);
                $lastPage = min($paginator->lastPage(), $paginator->currentPage() + (int) $window);
            @endphp
            <div class="pagination-pages" aria-label="{{ $pagesLabel }}">
                @foreach ($paginator->getUrlRange($firstPage, $lastPage) as $page => $url)
                    @if ($page === $paginator->currentPage())
                        <span class="pagination-page active" aria-current="page">{{ $page }}</span>
                    @else
                        <a wire:navigate class="pagination-page" href="{{ $withFragment($url) }}">{{ $page }}</a>
                    @endif
                @endforeach
            </div>
        @else
            <span @class([$stateClass => filled($stateClass)])>{{ $pageState }}</span>
        @endif

        @if ($paginator->hasMorePages())
            <a wire:navigate class="button button-secondary" href="{{ $withFragment($paginator->nextPageUrl()) }}" rel="next">{{ $nextLabel }} →</a>
        @else
            <span class="button button-secondary disabled">{{ $nextLabel }} →</span>
        @endif
    </nav>
@endif
