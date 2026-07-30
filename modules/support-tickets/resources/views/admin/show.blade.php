@extends('admin.layouts.panel')
@section('title', $ticket->subject)
@section('description', __('module-support-tickets::messages.ticket_number', ['number' => $ticket->number()]))
@section('content')
<livewire:support-tickets.admin-conversation :ticket-id="$ticket->id" :admin-path="$adminPath" />
@endsection
@push('head')
<link rel="stylesheet" href="{{ asset('assets/modules/support-tickets.css') }}?v={{ cms_version() }}" data-navigate-track>
@endpush
@push('scripts')
<script src="{{ asset('assets/modules/support-tickets.js') }}?v={{ cms_version() }}" defer data-navigate-track data-navigate-once></script>
@endpush
