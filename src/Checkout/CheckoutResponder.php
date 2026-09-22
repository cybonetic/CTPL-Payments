<?php

declare(strict_types=1);

namespace Ctpl\Payments\Checkout;

use Ctpl\Payments\Data\Checkout;
use Ctpl\Payments\Data\PaymentOrder;
use Ctpl\Payments\Enums\CheckoutType;
use Ctpl\Payments\Exceptions\PaymentsException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * Turn whatever the platform handed back into something to return from a
 * controller.
 *
 * ------------------------------------------------------------------
 *  WHY THIS IS NOT JUST `redirect($checkout->redirectUrl)`
 * ------------------------------------------------------------------
 *
 * There are five checkout types because gateways genuinely differ, and
 * which one a payment gets is decided by routing at the moment the
 * attempt is opened. An integration that read `redirect_url` and assumed
 * it worked is one that breaks the first time a payment is routed to a
 * gateway that hands back a form to POST or a payload for its own JS SDK
 * — and it breaks in front of a customer who is trying to pay.
 *
 * So the branch lives here, once, rather than in every controller.
 *
 * Two of the five the server can complete alone: `redirect` and
 * `form_post`. The other three need JavaScript on the page, and this
 * class will not pretend otherwise — `respond()` refuses them with a
 * message naming what to do, rather than redirecting somewhere that will
 * not work. `payloadFor()` gives you what to hand your front end.
 */
final class CheckoutResponder
{
    /**
     * A response that takes the customer to the gateway.
     *
     * @throws PaymentsException when the type needs a browser to do the
     *         handoff, or when there is no checkout at all.
     */
    public static function respond(Checkout|PaymentOrder $checkout): RedirectResponse|Response
    {
        $checkout = self::checkoutOf($checkout);

        if ($checkout->hasExpired()) {
            throw new PaymentsException(
                'This checkout session has expired. Open a new attempt on the order rather than sending '
                . 'the customer to a session the gateway will refuse.',
                type: 'session_not_usable',
            );
        }

        return match ($checkout->type) {
            CheckoutType::Redirect => self::redirect($checkout),
            CheckoutType::FormPost => self::formPost($checkout),

            // Deliberately a refusal rather than a best effort. Sending a
            // customer to an SDK payload's `redirect_url` — which for some
            // gateways exists and is not a checkout — shows them a page
            // that cannot take their money.
            CheckoutType::Sdk, CheckoutType::Intent, CheckoutType::Qr => throw new PaymentsException(
                sprintf(
                    'This payment was routed to a [%s] checkout, which the browser has to complete: hand '
                    . 'CheckoutResponder::payloadFor($order) to your front end and let the gateway\'s own '
                    . 'script, UPI intent or QR renderer take it from there. Returning a redirect here '
                    . 'would send the customer somewhere that cannot take their money.',
                    $checkout->type->value,
                ),
                type: 'checkout_needs_browser',
            ),
        };
    }

    /**
     * Everything the browser needs, as JSON-safe data.
     *
     * Safe to embed in a page: the session token is scoped to this one
     * payment, expires with the session, and cannot read anything else.
     * It is NOT your API token and the two must never be confused — that
     * one authenticates your whole application.
     *
     * @return array<string, mixed>
     */
    public static function payloadFor(Checkout|PaymentOrder $checkout): array
    {
        $checkout = self::checkoutOf($checkout);

        return [
            'type' => $checkout->type->value,
            'session_id' => $checkout->sessionId,
            'token' => $checkout->token,
            'redirect_url' => $checkout->redirectUrl,
            'public_key' => $checkout->publicKey,
            'payload' => $checkout->payload,
            'expires_in' => $checkout->expiresIn,
        ];
    }

    /** Can the server complete this handoff with no JavaScript? */
    public static function isServerDriven(Checkout|PaymentOrder $checkout): bool
    {
        return self::checkoutOf($checkout)->type->isServerDriven();
    }

    private static function redirect(Checkout $checkout): RedirectResponse
    {
        if ($checkout->redirectUrl === null || $checkout->redirectUrl === '') {
            throw new PaymentsException(
                'The gateway described a redirect checkout and gave no URL to redirect to. This is the '
                . 'platform\'s to fix; do not guess one.',
                type: 'malformed_response',
            );
        }

        return new RedirectResponse($checkout->redirectUrl);
    }

    /**
     * An auto-submitting form, built here rather than in a Blade view.
     *
     * Self-contained on purpose: a published view is one an application
     * can edit, and the thing most likely to be edited out is the
     * `noscript` submit button — which is the only thing standing between
     * a customer with JavaScript disabled and a blank page.
     *
     * Values are escaped with `htmlspecialchars` including quotes, because
     * a gateway's payload is somebody else's data appearing in our markup.
     */
    private static function formPost(Checkout $checkout): Response
    {
        if ($checkout->redirectUrl === null || $checkout->redirectUrl === '') {
            throw new PaymentsException(
                'The gateway described a form-post checkout and gave nowhere to post it to.',
                type: 'malformed_response',
            );
        }

        $escape = static fn (mixed $value): string => htmlspecialchars(
            is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );

        $action = $escape($checkout->redirectUrl);
        $fields = '';

        foreach ($checkout->payload as $name => $value) {
            $fields .= sprintf(
                '<input type="hidden" name="%s" value="%s">',
                $escape($name),
                $escape($value),
            );
        }

        $html = <<<HTML
            <!doctype html>
            <html lang="en"><head><meta charset="utf-8">
            <title>Taking you to your bank…</title>
            <meta name="viewport" content="width=device-width,initial-scale=1">
            </head>
            <body style="font-family:system-ui,sans-serif;text-align:center;padding:3rem 1rem">
            <p>Taking you to your bank to complete the payment. Please do not close this window.</p>
            <form id="ctpl-checkout" method="POST" action="{$action}">{$fields}
            <noscript><button type="submit">Continue to payment</button></noscript>
            </form>
            <script>document.getElementById('ctpl-checkout').submit();</script>
            </body></html>
            HTML;

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private static function checkoutOf(Checkout|PaymentOrder $checkout): Checkout
    {
        if ($checkout instanceof Checkout) {
            return $checkout;
        }

        return $checkout->checkout
            ?? $checkout->latestAttempt()?->checkout
            ?? throw new PaymentsException(
                'This order has no checkout to send the customer to. An order carries one only for as long '
                . 'as its attempt is live — open a new attempt to get a fresh one.',
                type: 'no_checkout',
            );
    }
}
