<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Support\Payments\DonationsTrustedAutoload;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Data\ProviderCallback;
use KaevCMS\Modules\Donations\Services\DonationPaymentSettlements;
use KaevCMS\Modules\Donations\Services\FreeKassaAdminTestPayments;
use KaevCMS\Modules\Donations\Services\FreeKassaCallbackSecurity;
use KaevCMS\Modules\Donations\Services\FreeKassaNotificationTransport;
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
        try {
            DonationsTrustedAutoload::register();
            // The raw TCP peer is authoritative. Forwarded headers may be read
            // only when the peer is an explicitly allowed reverse proxy.
            if (! app(FreeKassaCallbackSecurity::class)->allows($request)) {
                return response('Untrusted sender', 403);
            }
            $callback = new ProviderCallback(FreeKassaNotificationTransport::decode($request), [], '');
            // Dedicated test references are checked first, independent of the
            // provider's later enabled/live state. Tests can never enter Wallet.
            $testStatus = app(FreeKassaAdminTestPayments::class)->receiveIfTest($callback);
            if ($testStatus === null) {
                app(DonationPaymentSettlements::class)->receive($callback, 'freekassa');
            }

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
