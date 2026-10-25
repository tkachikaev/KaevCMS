@extends('admin.layouts.panel')

@section('title', __('drivers.title'))
@section('description', __('drivers.description'))

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/admin/css/universal-drivers.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush

@push('scripts')
    <script src="{{ asset('assets/admin/js/universal-drivers.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush

@section('content')
    <livewire:admin.universal-driver-manager />
@endsection
