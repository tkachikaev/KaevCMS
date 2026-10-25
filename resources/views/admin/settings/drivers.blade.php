@extends('admin.layouts.panel')

@section('title', __('drivers.title'))
@section('description', __('drivers.description'))

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/admin/css/universal-drivers.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush

@section('content')
    <livewire:admin.universal-driver-manager />
@endsection
