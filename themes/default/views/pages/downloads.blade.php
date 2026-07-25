@extends('theme::layouts.app')
@section('title', __('Files').' — '.site_name())
@section('content')
<section class="page-hero"><div class="container"><p class="eyebrow">{{ __('PREPARING TO PLAY') }}</p><h1>{{ __('Download client') }}</h1></div></section>
<section class="container page-content"><div class="panel prose"><h2>{{ __('Client and patch') }}</h2><p>{{ __('Links will be managed from the admin panel. This is currently a safe placeholder.') }}</p></div></section>
@endsection
