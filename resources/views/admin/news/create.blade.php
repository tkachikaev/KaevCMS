@extends('admin.layouts.panel')
@section('title', __('New news item'))
@section('description', __('Add content, images and publication settings.'))
@section('content')
<form method="POST" action="{{ route('admin.news.store') }}" class="content-editor" enctype="multipart/form-data">
    @include('admin.news._form')
</form>
@endsection
@push('scripts')
<script src="{{ asset('assets/admin/js/news-editor.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
<script src="{{ asset('assets/admin/js/localization.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
