# Changelog

## 1.1.4

The report, sharpened: "when I make the payment and the razorpay returns
with the success or failure response then it gets stuck at the spinner
instead of returning to the checkout response that other gateways do."

So the payment completes and the page is never told. Two defects, one of
them mine from 1.1.2.

- **Razorpay's callbacks now hand back for real.** `redirect: true` is
  supposed to mean Razorpay POSTs its signed response to `callback_url`
  itself. When it does not, the page does it by hand — a form POST to the
  same endpoint, carrying the same fields — from both `handler` and
  `modal.ondismiss`. A POST and not a redirect, because
  `razorpay_signature` is the only thing proving the handback is
  authentic, and `CheckoutSessionController::callback()` is the only
  caller of `verifyPayment()`. A GET would throw that away and look
  identical to a page refresh. It is still not an outcome: the platform
  verifies the signature and then runs its own status query (R1, R12).
- **The 1.1.2 watchdog could be silenced by a leftover iframe.** It asked
  whether any gateway element was on the page and returned if so.
  Razorpay leaves its container in the DOM after its modal closes — so in
  the exact situation the watchdog existed for, it found that container,
  concluded all was well, and said nothing. Arrival now buys TIME, not
  silence: five minutes while a customer may be typing a card, and then a
  message either way. Every path ends somewhere the customer can act.
- **And the check that missed it has been fixed.** It asserted
  `typeof o.handler === "function"` — that a function exists, which is not
  that it works, and it passed throughout. The callbacks are now invoked
  the way Razorpay invokes them, and what the page does next is recorded.
  There is also a browser case that reproduces the reported state exactly:
  the gateway opens, leaves its container, and says nothing.

## 1.1.3

Reported again: the checkout page showing "taking you to your payment
provider" and never doing anything else — this time with Razorpay, before
the customer had paid, while the other three gateways worked.

The causes have been different each time and the symptom identical, which
is the actual defect. A gateway's script can be blocked by a
content-security policy or a browser extension, its CDN can be
unreachable, and its own code can sit waiting on a value nobody passed
it. **None of those raise anything this page can catch**, and a spinner is
a promise that something is happening.

- **The spinner is on a clock.** Fifteen seconds with no gateway UI and no
  navigation, and the page stops pretending: it says what it was trying to
  do, says no money has been taken, and offers a way back to the platform's
  return leg so the payment can be resolved rather than abandoned. Proved
  in a browser by hanging the script request and waiting it out.
- **A gateway that cannot open is refused before its script is fetched.**
  Razorpay does not reject a missing publishable key — it OPENS and hangs
  on its own loading shield for ever, with no error and no callback. That
  was confirmed by loading the real `checkout.razorpay.com` script against
  a payload with `public_key` removed. The page now names the missing field
  and says whose problem it is. Cashfree is guarded the same way on
  `payment_session_id`.
- **Every failure states the diagnosis**, on the card and in the console —
  the provider, the checkout type, which payload keys arrived, whether a
  key was present, and the return URL.

## 1.1.2

Reported from a live install: a Razorpay checkout that still said "taking
you to your payment provider" **after the customer had paid**.

- **Razorpay's handback URL was read under the wrong name.** Razorpay's
  payload calls it `callback_url`; `return_url` is Cashfree's name for the
  same thing, and the checkout page read `return_url` for both. So
  `redirect: true` had nothing to redirect to: the modal took the money,
  closed, and left the page on its spinner. It now reads `callback_url`
  first and falls back to `return_url`.
- **And a way back even if Razorpay does not navigate.** A `handler` and an
  `ondismiss` both send the customer to the platform's return leg — because
  a customer can dismiss the window *after* paying, and "Razorpay should
  redirect" is not a thing to leave a paid customer's browser resting on.
  Neither is treated as an outcome; the platform asks the gateway (R1, R12).
- **The page no longer guesses which gateway it is for.** `Checkout` carries
  `provider`, which the platform now sends. Sniffing the payload's shape —
  "it has a payment_session_id, so it must be Cashfree" — is how a page ends
  up loading one gateway's script for another's payment. The sniffing
  remains as a fallback for an older orchestrator.
- **The browser checks stopped inventing their own payloads.** This is the
  root of both this defect and 1.1.1's: fixtures written here, checked
  against code written here. `tools/verify_checkout_page.mjs` now takes
  Razorpay's, Cashfree's and PayU's payloads from the orchestrator's own
  adapters, serialised exactly as the API sends them. Only `intent` and
  `qr` are still hand-built, and they are labelled as such.

## 1.1.1

- The hosted form now posts **every** key the platform sends except
  `action`, `method` and `display_amount`, and `CheckoutResponderTest`
  asserts PayU's own published mandatory list — `key`, `txnid`, `amount`,
  `productinfo`, `firstname`, `email`, `phone`, `surl`, `furl`, `hash` —
  rather than the subset the test's author happened to think of.
- The matching platform release adds `key` to PayU's checkout payload. It
  was never there: the merchant key went into the hash and into
  `public_key`, and not into the form the browser posts, so PayU answered
  "Mandatory parameter missing from your transaction request are: key,
  phone". Nothing in this package could have supplied it — an SDK that
  invented a field the platform did not sign for would break the hash —
  which is why this release is paired with a platform one.

## 1.1.0

Reported from a live install: a Cashfree payment showed the platform's JSON
outcome page, and "why sdk only takes to the phonepe, not to any one else".

### Fixed — the checkout types were wrong

- `CheckoutType` now carries **the platform's six**: `hosted`, `sdk`,
  `redirect`, `qr`, `intent`, `custom`. It previously invented `form_post`,
  which the platform has never sent, and was missing `hosted` and `custom`.
- That was worse than a naming slip. `Checkout::fromApi()` falls back to
  `Redirect` for a type it does not know, so **PayU** — which is `hosted`,
  a set of fields carrying a SHA-512 hash that must be POSTed — became a
  plain GET with no fields and no hash: a page that cannot take a
  customer's money. The package's own tests passed throughout, because they
  drove a type this package invented against a payload it also invented.
  `CheckoutResponderTest` now uses a real PayU-shaped payload.
- The hosted form posts to the payload's signed `action`, not to
  `redirect_url`, and leaves `action`, `method` and `display_amount` out of
  the fields — they are instructions about the form, not part of what was
  signed.

### Added — `Payments::checkout($order)`

- One line for every gateway. A redirect or the signed form where the
  server can finish; the new `ctpl-payments::checkout` page where it
  cannot — Razorpay's and Cashfree's own scripts, the UPI intent, the QR.
  No application writes gateway JavaScript any more.
- An `sdk` checkout from a provider the page does not know says so rather
  than guessing a script: a wrong script is a broken checkout in front of
  somebody trying to pay.
- Every failure on that page is reported as a failure to *hand off*, never
  as a failure to pay, and says no money has been taken.
- `php artisan vendor:publish --tag=ctpl-payments-views` to restyle it.
- `tools/verify_checkout_page.mjs` opens each type in a real browser and
  records which gateway script was actually fetched — which is the one
  thing the PHP suite cannot see, because both CDN URLs are literals in the
  page and the choice happens at runtime.

### Added — coming back to the page the payment started on

- `createOrder()` and `pay()` take `returnUrl:`, and `return_to_origin` in
  the config fills it with the current page automatically.
- Only `https` URLs are sent, and the platform checks the URL against the
  domains recorded for the application — at creation *and* again
  immediately before the browser moves.

## 1.0.1

- The orchestrator's URL is a constant, not a setting. An application
  configures a client id and secret and nothing else. A base URL that
  anything able to write to `.env` can change is one a leaked deploy
  credential can point at a host of its choosing, taking every payment
  and every access token with it.
- `CTPL_PAYMENTS_BASE_URL_OVERRIDE` exists for this package's own
  integration suite and is honoured only in `local` and `testing`.

## 1.0.0

First release. The application-facing surface of the CTPL Central Payment
Orchestrator, as a Laravel package.

- Orders, attempts, and a `pay()` that does both.
- `CheckoutResponder` for the checkout types, refusing the ones that need a
  browser rather than approximating them. (The vocabulary was wrong; see
  1.1.0.)
- `Payments::confirm()` — server-side confirmation, with backoff.
- Refunds, payment links, payment methods, gateway status.
- A signed webhook receiver dispatching Laravel events, with replay and
  duplicate protection.
- `Money`, which converts as a string so a float never reaches the wire.
- Typed exceptions that answer `retryable()`, so an `unresolved_attempt`
  is never retried and a `rate_limited` is.
- `Payments::fake()` for consuming applications' own tests, including
  `willBeDeclined()`, `willExpire()` and `willBeUnresolved()`.
- `php artisan ctpl:ping`.

Verified against a live orchestrator by `tools/verify_sdk.sh`: a real
payment taken to captured and refunded, a real payment link made and
withdrawn, and the webhook signature cross-checked against the platform's
own signer.
