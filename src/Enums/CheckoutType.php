<?php

declare(strict_types=1);

namespace Ctpl\Payments\Enums;

/**
 * How a customer is handed to a gateway.
 *
 * ------------------------------------------------------------------
 *  THESE ARE THE PLATFORM'S SIX, AND THEY USED NOT TO BE
 * ------------------------------------------------------------------
 *
 * This enum shipped with five cases, of which one — `form_post` — the
 * platform has never sent, and two of the platform's real ones —
 * `hosted` and `custom` — were missing entirely.
 *
 * That is worse than it sounds, because `Checkout::fromApi()` falls back
 * to `Redirect` for a type it does not recognise. PayU is routed
 * `hosted`: a set of form fields, a `hash` computed over them, and an
 * action URL to POST them to. Unrecognised, it became a plain redirect
 * — a GET to PayU's checkout with no fields and no hash, which is a
 * page that cannot take the customer's money. The SDK's own tests
 * passed throughout, because they tested the type this file invented
 * against a payload this file also invented.
 *
 * Kept deliberately narrow: the values are the platform's strings, and
 * an integration should branch on the CAPABILITY — `isServerDriven()` —
 * rather than on the case, so a seventh type does not mean a release.
 */
enum CheckoutType: string
{
    /**
     * The gateway's own page, reached by POSTing a signed set of fields.
     *
     * PayU. The payload is the form: every key is a field, and `action`
     * and `method` say where and how it goes. The hash in it is computed
     * by the platform from a salt the application never sees — so the
     * fields must be posted exactly as given, in full, unaltered.
     */
    case Hosted = 'hosted';

    /** Hand the payload to the gateway's own JS SDK. Razorpay, Cashfree. */
    case Sdk = 'sdk';

    /** Send the browser to `redirect_url`. PhonePe. */
    case Redirect = 'redirect';

    /** Render the payload as a QR code for another device to scan. */
    case Qr = 'qr';

    /** A UPI intent: open it on mobile, show the QR otherwise. */
    case Intent = 'intent';

    /** Something an adapter describes itself; read the payload. */
    case Custom = 'custom';

    /**
     * Can the server complete this handoff with no JavaScript at all?
     *
     * `redirect` is a `Location` header; `hosted` is a form the browser
     * submits, which works without script through its own submit button.
     * The rest need the page to do something.
     */
    public function isServerDriven(): bool
    {
        return $this === self::Redirect || $this === self::Hosted;
    }

    /**
     * Does `ctpl-payments::checkout` know how to render this?
     *
     * `custom` is the honest no: it means "an adapter described this
     * itself", and a view that guessed at an unknown payload would put
     * a broken page in front of a paying customer rather than an error
     * in front of a developer.
     */
    public function hasBuiltInCheckoutPage(): bool
    {
        return $this !== self::Custom;
    }
}
