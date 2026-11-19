@if($pager->hasPages())
    <nav class="forum-pagination" aria-label="{{ __('module-forum::messages.pages') }}">
        @if($pager->onFirstPage())
            <span class="forum-page-disabled">{{ __('module-forum::messages.previous') }}</span>
        @else
            <a href="{{ $pager->previousPageUrl() }}">{{ __('module-forum::messages.previous') }}</a>
        @endif
        @foreach($pager->getUrlRange(max(1, $pager->currentPage() - 2), min($pager->lastPage(), $pager->currentPage() + 2)) as $pageNumber => $url)
            @if($pager->currentPage() === $pageNumber)
                <strong aria-current="page">{{ $pageNumber }}</strong>
            @else
                <a href="{{ $url }}">{{ $pageNumber }}</a>
            @endif
        @endforeach
        @if($pager->hasMorePages())
            <a href="{{ $pager->nextPageUrl() }}">{{ __('module-forum::messages.next') }}</a>
        @else
            <span class="forum-page-disabled">{{ __('module-forum::messages.next') }}</span>
        @endif
    </nav>
@endif
