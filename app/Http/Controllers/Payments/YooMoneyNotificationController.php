<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Support\Payments\DonationsTrustedAutoload;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Data\ProviderCallback;
use KaevCMS\Modules\Donations\Services\DonationPaymentSettlements;
use KaevCMS\Modules\Donations\Services\DonationYooMoneyNotificationTest;
use LogicException;
use Throwable;

/** Independent of the module.enabled web UI middleware. Never exposes user orders. */
final class YooMoneyNotificationController extends Controller
{
    public function __invoke(Request $request): Response
    {
        if (config('donation-payments.callbacks_enabled') !== true) {
            return response('Unavailable', 503);
        }
        if (! $request->isSecure() && ! app()->environment('testing')) {
            return response('HTTPS required', 403);
        }
        if (! str_starts_with(strtolower((string) $request->header('content-type')), 'application/x-www-form-urlencoded')
            || strlen($request->getContent()) > 16384 || $request->getQueryString() !== null) {
            return response('Invalid notification', 400);
        }
        try {
            DonationsTrustedAutoload::register();
            // Only while the owner waits on the "Test" dialog, and only for YooMoney's test
            // notification; every other request takes the usual settlement path below.
            $notificationTest = app(DonationYooMoneyNotificationTest::class);
            if ($notificationTest->intercepts($request->getContent())) {
                return $notificationTest->accept($request->getContent())
                    ? response('OK', 200)
                    : response('Invalid notification', 400);
            }
            $status = app(DonationPaymentSettlements::class)->receive(new ProviderCallback(
                $request->getContent(), [], '',
            ));

            // YooMoney retries only non-200; all successful decisions are durable in DB.
            return response($status === 'credited' ? 'OK' : 'Recorded', 200);
        } catch (InvalidArgumentException|LogicException) {
            // Do not disclose merchant configuration, user existence, or expected amount.
            return response('Invalid notification', 400);
        } catch (Throwable) {
            // Failed storage, audit, or Wallet must be retried; never acknowledge lost work.
            return response('Unavailable', 503);
        }
    }
}
