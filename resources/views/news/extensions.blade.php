{{-- Optional CMS-owned extension point for modules displaying content after a news article. --}}
@foreach(app(\App\Support\News\NewsExtensionRegistry::class)->views($news) as $extension)
    {{ $extension }}
@endforeach
