@extends('account-theme::layouts.app')
@section('title', __('My characters'))
@section('description', __('Switch between the server hierarchy and one combined character list.'))
@section('content')
<x-account-page>
<section class="account-character-page">

    <livewire:account.character-directory />
</section>
</x-account-page>
@endsection
