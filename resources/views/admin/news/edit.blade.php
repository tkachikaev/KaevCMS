@extends('admin.layouts.panel')
@section('title', __('Edit news'))
@section('description', __('Changes appear on the public website after saving.'))
@section('content')
<form method="POST" action="{{ route('admin.news.update', $newsItem) }}" class="content-editor" enctype="multipart/form-data">
    @include('admin.news._form')
</form>
@endsection
@push('scripts')
<script src="{{ asset('assets/admin/js/news-editor.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
<script src="{{ asset('assets/admin/js/localization.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
