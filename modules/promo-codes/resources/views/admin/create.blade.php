@extends('admin.layouts.panel')
@section('title', __('module-promo-codes::messages.create_title'))
@section('description', __('module-promo-codes::messages.create_description'))
@section('content')
<form method="POST" action="{{ route('admin.module-pages.promo-codes.store', ['adminPath' => request()->route('adminPath')]) }}" class="content-editor">
    @include('module-promo-codes::admin._form')
</form>
@endsection
@push('scripts')
<script src="{{ asset('assets/admin/js/promo-codes.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
