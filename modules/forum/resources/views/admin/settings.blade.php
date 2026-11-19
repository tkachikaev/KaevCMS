@extends('admin.layouts.panel')
@section('title', __('module-forum::messages.settings'))
@section('description', __('module-forum::messages.settings_description'))
@section('content')
<x-admin.card narrow>
    <x-admin.card-heading
        :title="__('module-forum::messages.settings')"
        :description="__('module-forum::messages.settings_pending')"
    />
    <a href="{{ route('modules.forum.index') }}" class="admin-button">
        {{ __('module-forum::messages.back_to_forum') }}
    </a>
</x-admin.card>
@endsection
