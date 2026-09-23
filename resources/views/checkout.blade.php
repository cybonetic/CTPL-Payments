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
        function stuck(what) {
            spin.style.display = 'none';
            message.textContent = 'We could not reach your payment provider.';
            error.textContent = what + ' No money has been taken. Please try again.';
            error.style.display = 'block';
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
                        callback_url: payload.return_url,
                        redirect: true,
                        modal: {
                            ondismiss: function () {
                                spin.style.display = 'none';
                                message.textContent = 'You closed the payment window before finishing.';
                                if (payload.return_url) {
                                    window.location.href = payload.return_url;
                                }
                            }
                        }
                    };

                    try { new Razorpay(options).open(); } catch (e) { stuck(String(e && e.message || e)); }
                });
            },

            /*
             * Cashfree. Authenticated by the session id rather than a
             * key, which is why `public_key` is null for this gateway
             * and why nothing here looks for one.
             */
            sdk_cashfree: function () {
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
        var provider = String(payload.provider || payload.gateway || '').toLowerCase();

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
