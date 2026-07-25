@extends('admin.layouts.panel')

@section('title', __('Login servers'))
@section('description', __('LoginServer database connections used by game worlds and game accounts.'))

@section('content')
    <livewire:admin.login-server-manager />
@endsection

