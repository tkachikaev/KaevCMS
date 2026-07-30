@extends('account-theme::layouts.app')
@section('title', __('module-support-tickets::messages.account_title'))
@section('content')
<livewire:support-tickets.account-index />
@endsection
@push('head')
<link rel="stylesheet" href="{{ asset('assets/modules/support-tickets.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush
@push('scripts')
<script src="{{ asset('assets/modules/support-tickets.js') }}?v={{ cms_version() }}" defer data-navigate-track data-navigate-once></script>
@endpush
