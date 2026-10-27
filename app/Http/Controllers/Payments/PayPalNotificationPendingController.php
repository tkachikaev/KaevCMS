<?php

namespace App\Http\Controllers\Payments;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Iteration 3 only. Never acknowledge an unverified PayPal webhook. */
final class PayPalNotificationPendingController
{
    public function __invoke(Request $request): Response
    {
        if ((int) $request->server('CONTENT_LENGTH', 0) > 65536 || strlen($request->getContent()) > 65536) {
            return response('Payload too large', 413);
        }
        return response('PayPal webhook verification not active', 503);
    }
}
