<?php

namespace KaevCMS\Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Admin\AdminPathSettings;
use App\Support\Modules\ModuleAdminAuthorizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Services\PayPalAdminSettings;
use KaevCMS\Modules\Donations\Services\PayPalAdminTestPayments;
use KaevCMS\Modules\Donations\Services\PayPalOrdersApi;
use LogicException;
use RuntimeException;

final class AdminPayPalController extends Controller
{
    private function authorize(Request $request, ModuleAdminAuthorizer $access, string $route, string $method): Admin
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);
        $access->authorize('admin.module-pages.donations.providers.paypal.'.$route, $method, $admin);
        return $admin;
    }

    public function show(Request $request, ModuleAdminAuthorizer $access, AdminPathSettings $paths,
        PayPalAdminSettings $settings, PayPalAdminTestPayments $tests): View
    {
        $this->authorize($request, $access, 'show', 'GET');
        $callbackUrl = route('payments.paypal.notify');
        return view('module-donations::admin.paypal', [
            'adminPath' => $paths->path(), 'settings' => $settings->state(),
            'currencies' => $settings->supportedCurrencies(), 'tests' => $tests->recent(),
            'callbackUrl' => $callbackUrl, 'webhookLive' => str_starts_with($callbackUrl, 'https://'),
            'events' => [
                'CHECKOUT.ORDER.APPROVED', 'CHECKOUT.PAYMENT-APPROVAL.REVERSED',
                'PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.DENIED',
                'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED', 'CUSTOMER.DISPUTE.CREATED',
            ],
        ]);
    }

    public function save(Request $request, ModuleAdminAuthorizer $access, AdminPathSettings $paths,
        PayPalAdminSettings $settings): RedirectResponse
    {
        $admin = $this->authorize($request, $access, 'save', 'PUT');
        $currency = $request->input('currency_codes', []);
        $fields = $request->input('credentials', []);
        if (! is_array($currency) || ! is_array($fields)) {
            return back()->withErrors(['paypal' => __('module-donations::messages.paypal_invalid')]);
        }
        try {
            $settings->save($admin, $currency, $fields);
        } catch (InvalidArgumentException|RuntimeException) {
            return back()->withErrors(['paypal' => __('module-donations::messages.paypal_invalid')]);
        }
        return redirect()->route('admin.module-pages.donations.providers.paypal.show', [
            'adminPath' => $paths->path(),
        ])->with('status', __('module-donations::messages.paypal_saved'));
    }

    public function check(Request $request, ModuleAdminAuthorizer $access,
        PayPalAdminSettings $settings, PayPalOrdersApi $api): RedirectResponse
    {
        $this->authorize($request, $access, 'check', 'POST');
        $env = $request->input('environment');
        if (! in_array($env, ['sandbox', 'live'], true)) {
            return back()->withErrors(['paypal_check' => __('module-donations::messages.paypal_invalid')]);
        }
        try {
            $api->accessToken($settings->credentials($env), true);
        } catch (InvalidArgumentException|RuntimeException) {
            return back()->withErrors(['paypal_check' => __('module-donations::messages.paypal_check_failed')]);
        }
        return back()->with('status', __('module-donations::messages.paypal_check_ok'));
    }

    public function start(Request $request, ModuleAdminAuthorizer $access, PayPalAdminTestPayments $tests): RedirectResponse
    {
        $admin = $this->authorize($request, $access, 'test.start', 'POST');
        $currency = $request->input('currency_code');
        $amount = $request->input('amount');
        if (! is_string($currency) || ! is_string($amount)) {
            return back()->withErrors(['paypal_test' => __('module-donations::messages.paypal_test_invalid')]);
        }
        try {
            $result = $tests->start($admin, $currency, $amount);
        } catch (InvalidArgumentException|LogicException|RuntimeException) {
            return back()->withErrors(['paypal_test' => __('module-donations::messages.paypal_test_failed')]);
        }
        return redirect()->away($result['url'], 303);
    }

    public function returned(int $attemptId, Request $request, ModuleAdminAuthorizer $access,
        PayPalAdminTestPayments $tests, AdminPathSettings $paths): View
    {
        $admin = $this->authorize($request, $access, 'test.return', 'GET');
        $token = $request->query('token');
        abort_unless(is_string($token), 400);
        try {
            $tests->findOwn($admin, $attemptId);
            $status = $tests->approvalStatus($admin, $attemptId, $token);
        } catch (InvalidArgumentException|LogicException|RuntimeException) {
            $status = 'UNKNOWN';
        }
        return view('module-donations::admin.paypal-return', [
            'status' => $status, 'attemptId' => $attemptId, 'adminPath' => $paths->path(),
        ]);
    }

    public function cancelled(int $attemptId, Request $request, ModuleAdminAuthorizer $access,
        PayPalAdminTestPayments $tests, AdminPathSettings $paths): RedirectResponse
    {
        $admin = $this->authorize($request, $access, 'test.cancel', 'GET');
        $tests->findOwn($admin, $attemptId);
        // A browser cancel is not payment evidence and must never erase the remote attempt.
        return redirect()->route('admin.module-pages.donations.providers.paypal.show', [
            'adminPath' => $paths->path(),
        ])->with('status', __('module-donations::messages.paypal_test_cancelled'));
    }

    public function capture(int $attemptId, Request $request, ModuleAdminAuthorizer $access,
        PayPalAdminTestPayments $tests, AdminPathSettings $paths): RedirectResponse
    {
        $admin = $this->authorize($request, $access, 'test.capture', 'POST');
        try {
            $status = $tests->capture($admin, $attemptId);
            $message = $status === 'COMPLETED' ? 'paypal_test_completed' : 'paypal_test_pending';
            return redirect()->route('admin.module-pages.donations.providers.paypal.show', [
                'adminPath' => $paths->path(),
            ])->with('status', __('module-donations::messages.'.$message));
        } catch (InvalidArgumentException|LogicException|RuntimeException) {
            return back()->withErrors(['paypal_test' => __('module-donations::messages.paypal_test_review')]);
        }
    }
}
