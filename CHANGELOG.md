# Changelog

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
- `CheckoutResponder` for all five checkout types, refusing the three that
  need a browser rather than approximating them.
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
