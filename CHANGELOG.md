# Changelog

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
