@extends('theme::layouts.app')
@push('head')
    <link rel="stylesheet" href="{{ asset('assets/css/downloads.css') }}?v={{ cms_version() }}&ui=editable-content" data-navigate-track>
@endpush
@section('title'){{ $page->seoTitleFor().' — '.site_name() }}@endsection
@if ($page->seoDescriptionFor() !== '')
    @section('meta_description'){{ $page->seoDescriptionFor() }}@endsection
@endif
@section('content')
<section class="page-hero">
    <div class="container">
        <p class="eyebrow">{{ __('Page information eyebrow') }}</p>
        <h1>{{ $page->titleFor() }}</h1>
    </div>
</section>
<section @class(['container page-content cms-page', 'download-page-content' => $isDownloadPage ?? false])>
    <article class="panel prose cms-page-prose">{!! $page->safeBodyHtml() !!}</article>
</section>
@endsection
