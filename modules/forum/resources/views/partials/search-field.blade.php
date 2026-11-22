{{-- Compact forum search beside the page heading; results open on the search page. --}}
<form class="forum-quick-search" action="{{ route('modules.forum.search') }}" method="GET" role="search">
    <button type="submit" aria-label="{{ __('module-forum::messages.forum_search') }}">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
    </button>
    <input type="search" name="q" minlength="2" maxlength="80" value="{{ $term ?? '' }}"
           aria-label="{{ __('module-forum::messages.search_query') }}"
           placeholder="{{ __('module-forum::messages.search_placeholder') }}"
           @if(($autofocus ?? false) === true) autofocus @endif>
</form>
