@extends('theme::layouts.app')
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
<section class="container page-content cms-page">
    <article class="panel prose cms-page-prose">{!! $page->safeBodyHtml() !!}</article>
</section>
@endsection
