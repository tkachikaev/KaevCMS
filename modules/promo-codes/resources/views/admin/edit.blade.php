@extends('admin.layouts.panel')
@section('title', __('module-promo-codes::messages.edit_title', ['code' => $promoCode->code]))
@section('description', __('module-promo-codes::messages.edit_description'))
@section('content')
<form method="POST" action="{{ route('admin.module-pages.promo-codes.update', ['adminPath' => request()->route('adminPath'), 'promoCode' => $promoCode]) }}" class="content-editor">
    @include('module-promo-codes::admin._form')
</form>
@if($canManage)
    <form
        id="delete-promo-code-form"
        method="POST"
        action="{{ route('admin.module-pages.promo-codes.destroy', ['adminPath' => request()->route('adminPath'), 'promoCode' => $promoCode]) }}"
        data-promo-delete-form
        data-confirm-message="{{ __('module-promo-codes::messages.delete_confirm', ['code' => $promoCode->code]) }}"
    >
        @csrf
        @method('DELETE')
    </form>
@endif
@endsection
@push('scripts')
<script src="{{ asset('assets/admin/js/promo-codes.js') }}?v={{ cms_version() }}" defer data-navigate-once></script>
@endpush
