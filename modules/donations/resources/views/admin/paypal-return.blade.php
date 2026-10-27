@extends('admin.layouts.panel')
@section('title', 'PayPal Sandbox')
@section('content')
@include('module-donations::admin.navigation')
<section class="form-card" data-testid="paypal-test-return">
    <h2>PayPal Sandbox</h2>
    <p>{{ __('module-donations::messages.paypal_return_status') }}: <strong>{{ $status }}</strong></p>
    <p class="admin-field-help">{{ __('module-donations::messages.paypal_return_help') }}</p>
    @if($status === 'APPROVED')
        <form method="POST" action="{{ route('admin.module-pages.donations.providers.paypal.test.capture', ['adminPath' => $adminPath, 'attemptId' => $attemptId]) }}">
            @csrf
            <button type="submit" class="button button-primary">{{ __('module-donations::messages.paypal_test_capture') }}</button>
        </form>
    @endif
    @if($errors->has('paypal_test'))<p class="admin-field-error" role="alert">{{ $errors->first('paypal_test') }}</p>@endif
    <a class="button button-secondary" href="{{ route('admin.module-pages.donations.providers.paypal.show', ['adminPath' => $adminPath]) }}">{{ __('module-donations::messages.provider_configure') }}</a>
</section>
@endsection
