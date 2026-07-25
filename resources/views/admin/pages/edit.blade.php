@extends('admin.layouts.panel')
@section('title', __('Edit page'))
@section('description', __('Changes appear on the public website after saving.'))
@section('content')
<form method="POST" action="{{ route('admin.pages.update', $pageItem) }}" class="content-editor">
    @include('admin.pages._form')
</form>
@endsection
@push('scripts')
<script src="{{ asset('assets/admin/js/news-editor.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
<script src="{{ asset('assets/admin/js/localization.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
