@extends('theme::layouts.app')
@section('scene', 'page')
@section('title', __('Files').' — '.site_name())
@section('content')
<section class="page-hero"><div class="container"><p class="eyebrow">{{ __('PREPARING TO PLAY') }}</p><h1>{{ __('Download client') }}</h1></div></section>
<section class="container page-content"><div class="panel prose"><h2>{{ __('Files are not available yet') }}</h2><p>{{ __('Download links are being prepared. Please check back later.') }}</p></div></section>
@endsection
