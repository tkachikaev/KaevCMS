@extends('admin.layouts.panel')
@section('title', __('New page'))
@section('description', __('Create a multilingual website page and choose where it appears.'))
@section('content')
<form method="POST" action="{{ route('admin.pages.store') }}" class="content-editor">
    @include('admin.pages._form')
</form>
@endsection
@push('scripts')
<script src="{{ asset('assets/admin/js/news-editor.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
<script src="{{ asset('assets/admin/js/localization.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
