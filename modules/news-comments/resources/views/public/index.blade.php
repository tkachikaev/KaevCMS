@push('head')
<link rel="stylesheet" href="{{ asset('assets/modules/news-comments.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush

@livewire('news-comments.thread', ['newsId' => (int) $news->id], key('news-comments-'.$news->id))
