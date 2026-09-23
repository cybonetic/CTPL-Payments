{{--
    The page that hands a customer to whichever gateway they were routed
    to.

    ------------------------------------------------------------------
     WHY THIS IS IN THE PACKAGE
    ------------------------------------------------------------------

    Reported from a live install: "why sdk only takes to the phonepe,
    not to any one else, if taken to anyother then it show the json".

    Routing picks the gateway at the moment an attempt is opened, and
    the gateways genuinely differ — PhonePe hands back a URL, PayU a
    signed form, Razorpay and Cashfree a payload for their own scripts.
    `CheckoutResponder` completes the first two from the server and
    refuses the rest, correctly, because they need a browser. That left
    every integrator to write the gateway JavaScript themselves, once
    per gateway, and to keep it right — and a customer routed to a
    gateway they had not written yet saw a JSON error.

    So this renders all of them. An integration returns

        return view('ctpl-payments::checkout', ['order' => $order]);

    and never writes a line of gateway script.

    ------------------------------------------------------------------
     WHAT IT DELIBERATELY DOES NOT DO
    ------------------------------------------------------------------

    It does not report an outcome. Every gateway here ends by returning
    the customer to the platform, which runs its own server-side status
    query and only then forwards them to the application (R1, R12). The
    handlers below therefore have nothing to decide: they hand off, and
    the ones that can fail locally say so and offer the customer a way
    to try again.

    Publish it with

        php artisan vendor:publish --tag=ctpl-payments-views

    if you want it in your own layout. The gateway scripts are loaded
    from each gateway's own CDN, which is the only place they may be
    loaded from.
--}}
@php
    /** @var \Ctpl\Payments\Data\PaymentOrder|\Ctpl\Payments\Data\Checkout $checkout */
    $data = \Ctpl\Payments\Checkout\CheckoutResponder::payloadFor($order ?? $checkout);
    $type = $data['type'];
    $payload = $data['payload'];
    $title = $title ?? 'Completing your payment';
@endphp
    <!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: #f6f7f9; color: #14161a;
        }
        @media (prefers-color-scheme: dark) { body { background: #0d0f12; color: #e8eaed; } }
        .card {
            max-width: 26rem; width: 100%; margin: 1rem; padding: 2rem 1.5rem; text-align: center;
            background: #fff; border-radius: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.08);
        }
        @media (prefers-color-scheme: dark) { .card { background: #16191e; box-shadow: none; } }
        .amount { font-size: 1.6rem; font-weight: 600; margin: .25rem 0 1rem; }
        .muted { font-size: .8rem; opacity: .65; line-height: 1.5; }
        .spin {
            width: 26px; height: 26px; margin: 0 auto 1rem; border-radius: 50%;
            border: 3px solid rgba(127,127,127,.25); border-top-color: currentColor;
            animation: r .8s linear infinite;
        }
        @keyframes r { to { transform: rotate(360deg); } }
        .btn {
            display: inline-block; margin-top: 1rem; padding: .6rem 1.2rem; border: 0; border-radius: 999px;
            background: #1f6feb; color: #fff; font: inherit; font-size: .875rem; cursor: pointer;
            text-decoration: none;
        }
        .err { display: none; margin-top: 1rem; font-size: .8rem; color: #b42318; line-height: 1.5; }
        code { font-size: .75rem; }
        img.qr { width: 220px; height: 220px; image-rendering: pixelated; }
    </style>
</head>
<body>
<div class="card">
    <div class="spin" id="ctpl-spin"></div>

    @if (! empty($payload['display_amount']))
        <div class="amount">{{ $payload['display_amount'] }}</div>
    @endif

    <p id="ctpl-message">Taking you to your payment provider. Please do not close this window.</p>

    <p class="err" id="ctpl-error"></p>

    <noscript>
        <p class="muted">
            This page needs JavaScript to reach your payment provider. Please enable it and reload.
        </p>
    </noscript>
</div>

<script>
    (function () {
        'use strict';

        var payload = @json($payload);
        var publicKey = @json($data['public_key']);

        /*
         * ------------------------------------------------------------------
         *  EACH GATEWAY NAMES THE RETURN LEG DIFFERENTLY
         * ------------------------------------------------------------------
         *
         * Razorpay's payload calls it `callback_url`; Cashfree's calls it
         * `return_url`. This page read `return_url` for both — so for a
         * Razorpay payment it was `undefined`, and `redirect: true` with
         * no callback URL means Razorpay opens the modal, takes the
         * money, and then has nowhere to send the customer. The modal
         * closes and the page underneath is still the spinner.
         *
         * Reported from a live install, as a checkout that said "taking
         * you to your payment provider" forever, AFTER the customer had
         * paid.
         *
         * Every check passed, because the fixture those checks ran on had
         * `return_url` in it — a payload written here rather than one the
         * platform produces. `tools/verify_checkout_page.mjs` now takes
         * its payloads from the orchestrator's own adapters, which is the
         * only way this class of mistake gets caught.
         */
        var returnUrl = payload.callback_url || payload.return_url || null;
        var sessionUrl = @json($data['session_url'] ?? null);
        /*
         * ------------------------------------------------------------------
         *  LET THE PLATFORM SEE THE PAYER, ONCE, BEFORE HANDING OFF
         * ------------------------------------------------------------------
         *
         * An order is created by your server, so the address on that
         * request is your data centre. From here the customer goes
         * straight to the gateway. Without this call the platform never
         * sees their browser at all, and every payer field on the
         * payment is empty — address, device, location, network.
         * Reported from a live install exactly that way.
         *
         * `no-cors`, deliberately. Nothing here reads the response: the
         * checkout is already on this page. A no-cors GET is a simple
         * request, so it needs no preflight and no CORS headers on the
         * platform, and the REQUEST still arrives even though the reply
         * is withheld from this script. Asking for a readable response
         * would make recording the payer depend on a cross-origin policy
         * that has nothing to do with it.
         *
         * `keepalive`, so a browser that navigates to the gateway a
         * moment later does not cancel it in flight.
         *
         * Not awaited, and failure is ignored. The customer is here to
         * pay; a diagnostic field is never worth delaying a handoff or
         * failing one.
         */
        if (sessionUrl && window.fetch) {
            try {
                fetch(sessionUrl, {
                    method: 'GET',
                    mode: 'no-cors',
                    credentials: 'omit',
                    cache: 'no-store',
                    keepalive: true,
                }).catch(function () {});
            } catch (e) { /* never blocks the payment */ }
        }

        var spin = document.getElementById('ctpl-spin');
        var message = document.getElementById('ctpl-message');
        var error = document.getElementById('ctpl-error');

        /*
         * Every failure here is a failure to HAND OFF, never a failure
         * to pay: nothing on this page can know whether money moved, and
         * saying so would be the redirect-is-evidence mistake in a new
         * place. So the customer is told the handoff did not work and
         * offered another go.
         */
        var handedOff = false;

        /*
         * Every failure here is a failure to HAND OFF, never a failure
         * to pay: nothing on this page can know whether money moved, and
         * saying so would be the redirect-is-evidence mistake in a new
         * place. So the customer is told the handoff did not work and
         * given somewhere to go.
         */
        function stuck(what) {
            if (handedOff) { return; }

            handedOff = true;
            clearTimeout(watchdog);

            spin.style.display = 'none';
            message.textContent = 'We could not reach your payment provider.';
            error.textContent = what + ' No money has been taken.';
            error.style.display = 'block';

            /*
             * A way out, not a dead end.
             *
             * The return leg belongs to the platform, which looks the
             * payment up server-side and sends the customer where their
             * application wants them. Better than "please try again" at
             * a page that has already failed once.
             */
            if (returnUrl) {
                var a = document.createElement('a');
                a.className = 'btn';
                a.href = returnUrl;
                a.textContent = 'Go back and try again';
                error.parentNode.appendChild(a);
            }

            // For whoever is looking at a console rather than at a card.
            if (window.console && console.error) {
                console.error('[ctpl-payments] handoff failed:', what, {
                    provider: provider || '(not named by the platform)',
                    type: type,
                    payloadKeys: Object.keys(payload),
                    hasPublicKey: Boolean(publicKey),
                    returnUrl: returnUrl,
                });
            }
        }

        /*
         * ------------------------------------------------------------------
         *  A PAGE THAT CANNOT SPIN FOREVER
         * ------------------------------------------------------------------
         *
         * Reported twice from a live install: this page showing "taking
         * you to your payment provider" and never doing anything else.
         *
         * The causes were different each time and the symptom was
         * identical, which is the real defect — a spinner is a promise
         * that something is happening, and there was nothing keeping
         * that promise honest. A gateway's script can be blocked by a
         * content-security policy or an extension, its CDN can be
         * unreachable, and its own code can sit waiting on a value
         * nobody passed it. None of those raise an error this page can
         * catch.
         *
         * So the spinner is now on a clock. If no gateway UI has
         * appeared and the browser has not navigated away, the page
         * stops pretending and says what it was trying to do.
         */
        var HANDOFF_SECONDS = 15;

        /*
         * Long enough for somebody to actually pay.
         *
         * Once the gateway's own UI is up, the customer may be reading a
         * card, waiting for an OTP, or switching to their bank's app. The
         * page must not interrupt that — but it must not wait for ever
         * either, because "the gateway finished and nothing happened" is
         * precisely the state being fixed.
         */
        var PAYING_SECONDS = 300;

        var watchdog = null;

        function gatewayUiIsUp() {
            return document.querySelector('iframe, [class*="razorpay"], [class*="cashfree"]') !== null;
        }

        /*
         * ------------------------------------------------------------------
         *  THE WATCHDOG THAT COULD BE SILENCED BY A LEFTOVER IFRAME
         * ------------------------------------------------------------------
         *
         * The first version of this asked whether any gateway element was
         * on the page and, if so, returned — reasoning that the gateway
         * had arrived and the rest was the customer's business.
         *
         * Razorpay leaves its container in the DOM after its modal
         * closes. So for the exact case this was written for — reported
         * from a live install as "razorpay returns with the success or
         * failure response then it gets stuck at the spinner" — the
         * check found an iframe, concluded all was well, and said
         * nothing. A watchdog that goes quiet in the one situation it
         * exists for is worse than no watchdog: it makes the page look
         * supervised.
         *
         * So arrival no longer BUYS silence, it buys TIME. Every path
         * ends in a state the customer can act on.
         */
        function arm(seconds) {
            clearTimeout(watchdog);

            watchdog = setTimeout(function () {
                if (handedOff) { return; }

                if (gatewayUiIsUp()) {
                    // Up, and we are still here. Give the customer the
                    // long clock once, then say something regardless.
                    if (seconds < PAYING_SECONDS) { return arm(PAYING_SECONDS); }

                    return stuck('Your payment provider opened but this page was never told what '
                        + 'happened. If you completed the payment, do not pay again \u2014 use the link '
                        + 'below and the outcome will be looked up.');
                }

                stuck('Your payment provider did not respond within ' + seconds + ' seconds. '
                    + 'This is usually a script blocked by a browser extension or a content-security '
                    + 'policy, or a gateway that is not reachable from this page.');
            }, seconds * 1000);
        }

        arm(HANDOFF_SECONDS);

        /*
         * ------------------------------------------------------------------
         *  LEAVING, WITH WHATEVER THE GATEWAY HANDED BACK
         * ------------------------------------------------------------------
         *
         * `redirect: true` is supposed to mean Razorpay POSTs its signed
         * response to `callback_url` itself and this page is gone. When
         * it does not — and from a live install, it does not — the
         * customer is left looking at a spinner with the money taken.
         *
         * So the JS callback path does the same thing by hand: a form
         * POST to the same endpoint, carrying the same fields. Two
         * reasons it is a POST and not `location.href`:
         *
         *   The platform VERIFIES that payload. `razorpay_signature` is
         *   the only thing that proves the handback is authentic, and
         *   `CheckoutSessionController::callback()` is the first and
         *   only caller of `verifyPayment()`. A bare GET throws that
         *   evidence away.
         *
         *   And a GET would be indistinguishable from a customer
         *   refreshing the page.
         *
         * It is still not an OUTCOME. The platform verifies the
         * signature and then runs its own server-side status query
         * anyway (R1, R12) — an authentic message saying "success" proves
         * Razorpay sent it, not that the money settled.
         */
        function leaveForPlatform(fields) {
            if (!returnUrl) {
                return stuck('The platform sent nowhere to return to, so this page cannot hand the '
                    + 'payment back. An operator needs to look at this application\u2019s return URLs.');
            }

            handedOff = true;
            clearTimeout(watchdog);

            message.textContent = 'Checking your payment\u2026';

            var form = document.createElement('form');
            form.method = 'POST';
            form.action = returnUrl;
            form.style.display = 'none';

            var body = fields && typeof fields === 'object' ? fields : {};

            for (var name in body) {
                if (!Object.prototype.hasOwnProperty.call(body, name)) { continue; }
                if (body[name] === null || typeof body[name] === 'object') { continue; }

                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = String(body[name]);
                form.appendChild(input);
            }

            document.body.appendChild(form);
            form.submit();

            /*
             * And if even that does not move the browser — a sandboxed
             * frame, a navigation policy — say so rather than leaving a
             * paid customer on a spinner for the third time.
             */
            setTimeout(function () {
                handedOff = false;
                stuck('Your payment was completed but this page could not hand it back automatically. '
                    + 'Do not pay again \u2014 use the link below and the outcome will be looked up.');
            }, 6000);
        }

        function load(src, onready) {
            var s = document.createElement('script');
            s.src = src;
            s.async = true;
            s.onload = onready;
            s.onerror = function () { stuck('The provider’s script did not load.'); };
            document.head.appendChild(s);
        }

        var handlers = {

            /*
             * Razorpay. `public_key` is the key id, which is public by
             * design and belongs in a browser; the secret never leaves
             * the platform.
             */
            sdk_razorpay: function () {
                /*
                 * ------------------------------------------------------------------
                 *  CHECKED BEFORE THE SCRIPT IS EVEN FETCHED
                 * ------------------------------------------------------------------
                 *
                 * Razorpay's checkout does not refuse a missing key. It
                 * OPENS, and then sits on its own loading shield for
                 * ever — no error, no callback, nothing this page can
                 * catch. Confirmed by loading the real script against a
                 * payload with `public_key` removed: the modal appears
                 * and never finishes.
                 *
                 * That is a stuck payment with no diagnosis, so it is
                 * refused here where the message can name the field. The
                 * key is the platform's to send — it comes from the
                 * gateway account's credentials — so this is an operator
                 * problem and the message says so.
                 */
                if (!publicKey) {
                    return stuck('The platform sent no publishable key for Razorpay, and Razorpay\u2019s '
                        + 'checkout hangs silently without one. The key comes from this gateway '
                        + 'account\u2019s credentials \u2014 an operator needs to check them.');
                }

                if (!payload.gateway_order_id) {
                    return stuck('The platform sent no Razorpay order id, so there is nothing to pay against.');
                }

                load('https://checkout.razorpay.com/v1/checkout.js', function () {
                    if (typeof Razorpay === 'undefined') { return stuck('The provider’s script did not start.'); }

                    var options = {
                        key: publicKey,
                        order_id: payload.gateway_order_id,
                        amount: payload.amount,
                        currency: payload.currency,
                        name: payload.name || document.title,
                        description: payload.description || '',
                        prefill: payload.prefill || {},
                        /*
                         * The handback goes to the PLATFORM, and it is
                         * not what decides the outcome — the platform
                         * verifies the signature on it and then runs its
                         * own status query regardless.
                         */
                        callback_url: returnUrl,
                        redirect: true,

                        /*
                         * `redirect: true` should mean Razorpay posts the
                         * handback to `callback_url` itself and this
                         * never runs. It is here because "should" is not
                         * a thing to leave a paid customer's browser
                         * resting on: a blocked navigation, a popup
                         * policy, or a Razorpay change, and without this
                         * the page sits on a spinner with the money
                         * taken. Nothing here is treated as an outcome —
                         * it goes to the platform, which asks the
                         * gateway (R1, R12).
                         */
                        /*
                         * Razorpay calls this with its signed response
                         * when it does NOT redirect. Handed straight to
                         * the platform, fields and all.
                         */
                        handler: function (response) {
                            leaveForPlatform(response);
                        },
                        modal: {
                            ondismiss: function () {
                                /*
                                 * A customer can close the window AFTER
                                 * paying, so this is not treated as
                                 * "they gave up". It goes to the
                                 * platform with nothing to verify, and
                                 * the status query decides — which is
                                 * the only thing that ever decides.
                                 */
                                leaveForPlatform({});
                            }
                        }
                    };

                    try {
                        new Razorpay(options).open();
                    } catch (e) {
                        return stuck(String(e && e.message || e));
                    }
                });
            },

            /*
             * Cashfree. Authenticated by the session id rather than a
             * key, which is why `public_key` is null for this gateway
             * and why nothing here looks for one.
             */
            sdk_cashfree: function () {
                // Cashfree's browser SDK authenticates with the session
                // id and nothing else, so this is the one field it
                // cannot be opened without.
                if (!payload.payment_session_id) {
                    return stuck('The platform sent no Cashfree payment session id, so its checkout '
                        + 'cannot be opened.');
                }

                load('https://sdk.cashfree.com/js/v3/cashfree.js', function () {
                    if (typeof Cashfree === 'undefined') { return stuck('The provider’s script did not start.'); }

                    try {
                        Cashfree({ mode: payload.environment === 'production' ? 'production' : 'sandbox' })
                            .checkout({
                                paymentSessionId: payload.payment_session_id,
                                redirectTarget: '_self'
                            });
                    } catch (e) {
                        stuck(String(e && e.message || e));
                    }
                });
            },

            /*
             * A UPI intent. On a phone the link opens the customer's UPI
             * app; on a desktop it opens nothing at all, so the QR is
             * shown instead — which is the same payment, scanned.
             */
            intent: function () {
                var onPhone = /android|iphone|ipad|ipod/i.test(navigator.userAgent);

                if (onPhone && payload.intent_url) {
                    message.textContent = 'Opening your UPI app…';
                    window.location.href = payload.intent_url;

                    // If nothing took the link, the customer is still here.
                    setTimeout(function () {
                        spin.style.display = 'none';
                        message.textContent = 'If your UPI app did not open, tap below.';
                        var a = document.createElement('a');
                        a.className = 'btn';
                        a.href = payload.intent_url;
                        a.textContent = 'Open UPI app';
                        message.parentNode.appendChild(a);
                    }, 2500);

                    return;
                }

                handlers.qr('Scan this with any UPI app to pay.');
            },

            qr: function (instruction) {
                handedOff = true;
                clearTimeout(watchdog);
                spin.style.display = 'none';
                message.textContent = instruction || 'Scan this code with your payment app.';

                var src = payload.qr_image || payload.qr_code_url;

                if (src) {
                    var img = document.createElement('img');
                    img.className = 'qr';
                    img.alt = 'Payment QR code';
                    img.src = src;
                    message.parentNode.insertBefore(img, message.nextSibling);
                } else if (payload.qr_data || payload.intent_url) {
                    var pre = document.createElement('code');
                    pre.textContent = payload.qr_data || payload.intent_url;
                    message.parentNode.insertBefore(pre, message.nextSibling);
                } else {
                    return stuck('The provider sent no code to show.');
                }

                var note = document.createElement('p');
                note.className = 'muted';
                note.style.marginTop = '1rem';
                note.textContent = 'This page will not update by itself. Once you have paid, '
                    + 'return to the site you started from.';
                message.parentNode.appendChild(note);
            }
        };

        var type = @json($type);

        /*
         * The gateway, BY NAME, from the platform.
         *
         * `checkout.provider` is what the platform says it routed to.
         * The payload sniffing below it is a fallback for an older
         * orchestrator that does not send it yet — and sniffing is
         * exactly how a page ends up loading one gateway's script for
         * another's payment, so it is the fallback and not the rule.
         */
        var provider = String(
            @json($data['provider'] ?? null) || payload.provider || payload.gateway || ''
        ).toLowerCase();

        if (type === 'sdk') {
            /*
             * Which script to load is decided by the provider, and the
             * platform names it. An unknown one is NOT guessed at: a
             * wrong script is a broken checkout in front of a paying
             * customer, and an honest message is better than that.
             */
            if (provider.indexOf('razorpay') !== -1) { handlers.sdk_razorpay(); }
            else if (provider.indexOf('cashfree') !== -1) { handlers.sdk_cashfree(); }
            else if (payload.payment_session_id) { handlers.sdk_cashfree(); }
            else if (publicKey && payload.gateway_order_id) { handlers.sdk_razorpay(); }
            else { stuck('This payment was routed to a provider this page does not know how to open.'); }
        } else if (type === 'intent') {
            handlers.intent();
        } else if (type === 'qr') {
            handlers.qr();
        } else {
            stuck('This checkout type (' + type + ') has to be completed by the server.');
        }
    })();
</script>
</body>
</html>
