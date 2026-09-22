<?php

declare(strict_types=1);

namespace Ctpl\Payments\Enums;

/**
 * How a customer is handed to a gateway.
 *
 * Five of them, because gateways genuinely differ, and an integration
 * that assumed `redirect_url` was always there is one that breaks the day
 * routing sends a payment somewhere else. `CheckoutResponder` turns any of
 * these into a Laravel response so you do not have to branch at all.
 */
enum CheckoutType: string
{
    /** Send the browser to `redirect_url`. */
    case Redirect = 'redirect';

    /** POST the payload as a form to `redirect_url`. */
    case FormPost = 'form_post';

    /** Hand the payload and public key to the gateway's own JS SDK. */
    case Sdk = 'sdk';

    /** A UPI intent: open it on mobile, show the QR otherwise. */
    case Intent = 'intent';

    /** Render the payload as a QR code. */
    case Qr = 'qr';

    /** Can the server hand this off on its own, with no JavaScript? */
    public function isServerDriven(): bool
    {
        return $this === self::Redirect || $this === self::FormPost;
    }
}
