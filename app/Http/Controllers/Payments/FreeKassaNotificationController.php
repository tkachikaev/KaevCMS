<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Support\Payments\DonationsTrustedAutoload;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Data\ProviderCallback;
use KaevCMS\Modules\Donations\Services\DonationPaymentSettlements;
use LogicException;
use Throwable;

/**
 * Permanent core-owned callback URL, independent of the Donations UI switch.
 * Iteration 2 handles ONLY test preparations. Live settlement stays disabled
 * in FreeKassaProvider until trusted IP and server-side API checks are ready.
 */
final class FreeKassaNotificationController extends Controller
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
            || $request->getQueryString() !== null || strlen($request->getContent()) > 16384) {
            return response('Invalid notification', 400);
        }
        try {
            DonationsTrustedAutoload::register();
            $status = app(DonationPaymentSettlements::class)->receive(new ProviderCallback(
                $request->getContent(), [], '',
            ), 'freekassa');

            // FreeKassa expects precisely YES after durable processing. Stored
            // test/review/pending receipts are also acknowledged (never credited).
            return response('YES', 200)->header('Content-Type', 'text/plain; charset=UTF-8');
        } catch (InvalidArgumentException|LogicException) {
            return response('Invalid notification', 400);
        } catch (Throwable) {
            return response('Unavailable', 503);
        }
    }
}
