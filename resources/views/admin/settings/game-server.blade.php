@extends('admin.layouts.panel')

@section('title', __('Game servers'))
@section('description', __('Game worlds displayed on the public website.'))

@section('content')
    <livewire:admin.game-server-manager />
@endsection

