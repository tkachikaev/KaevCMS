<?php

namespace App\Http\Controllers\Payments;

use App\Support\Payments\DonationsTrustedAutoload;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use KaevCMS\Modules\Donations\Services\PayPalWebhooks;
use LogicException;
use Throwable;

/** Permanent core route: verify first, persist a durable event, then acknowledge. */
final class PayPalNotificationController
{
    public function __invoke(Request $request): Response
    {
        if ((int) $request->server('CONTENT_LENGTH', 0) > 65536 || strlen($request->getContent()) > 65536) {
            return response('Payload too large', 413);
        }
        if (config('donation-payments.callbacks_enabled') !== true) {
            return response('Unavailable', 503);
        }
        if (! $request->isSecure() && ! app()->environment('testing')) {
            return response('HTTPS required', 403);
        }
        if (! str_starts_with(strtolower((string) $request->header('Content-Type')), 'application/json')) {
            return response('JSON required', 415);
        }
        $headers = [];
        foreach (['paypal-transmission-id', 'paypal-transmission-time', 'paypal-cert-url',
            'paypal-auth-algo', 'paypal-transmission-sig'] as $name) {
            $headers[$name] = $request->header($name);
        }
        try {
            DonationsTrustedAutoload::register();
            app(PayPalWebhooks::class)->receive($request->getContent(), $headers);

            return response('OK', 200);
        } catch (InvalidArgumentException|LogicException|\JsonException) {
            return response('Invalid notification', 400);
        } catch (Throwable) {
            // A temporary API/SQL failure must NOT acknowledge the message.
            return response('Unavailable', 503);
        }
    }
}
