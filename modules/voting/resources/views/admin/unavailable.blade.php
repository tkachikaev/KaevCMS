@extends('admin.layouts.panel')
@section('title', __('module-voting::messages.title'))
@section('description', __('module-voting::messages.description'))
@section('docs', 'modules')
@section('content')
    @include('module-voting::admin.navigation')
    <x-admin.card>
        <x-admin.card-heading :title="__('module-voting::messages.'.$section)" />
        <div class="admin-empty-state empty-state"><p>{{ __('module-voting::messages.'.$section.'_unavailable') }}</p></div>
    </x-admin.card>
@endsection
