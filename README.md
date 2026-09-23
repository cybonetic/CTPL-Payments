# CTPL Payments for Laravel

Take payments through the CTPL Central Payment Orchestrator from a Laravel
application.

```php
use Ctpl\Payments\Checkout\CheckoutResponder;
use Ctpl\Payments\Data\{Customer, Money};
use Ctpl\Payments\Facades\Payments;

$order = Payments::pay(
    reference: "BOOKING-{$booking->id}",
    amount: Money::rupees(1250.50),
    customer: new Customer($patient->id, $patient->name, $patient->email, $patient->phone),
);

return CheckoutResponder::respond($order);   // the customer is at their bank
```

and later, on your own server:

```php
$order = Payments::confirm($order);          // asks the platform, waits for a real answer

if ($order->isPaid()) {
    $booking->markPaid();
}
```

---

## Install

```bash
composer require cybonetic/ctpl-payments-laravel
php artisan vendor:publish --tag=ctpl-payments-config   # optional
```

```dotenv
CTPL_PAYMENTS_CLIENT_ID=ctpl_clinic_xxxxxxxxxxxx
CTPL_PAYMENTS_CLIENT_SECRET=...
```

That is the whole configuration. **There is no URL to set** — the
orchestrator's address is a constant in the package, and deliberately not
something an application can change. A base URL that anything able to
write a line into `.env` can change is one that a leaked deploy
credential can quietly point at a host of its choosing, taking every
payment and every access token with it.

Staging is not a different host either: which gateway accounts a payment
may use is decided by the environment of the *application* your credential
belongs to, on the platform side. A staging credential cannot reach
production money.

Credentials are issued per application from the operator portal, and the
secret is shown once. Then:

```bash
php artisan ctpl:ping
```

which separates *the credentials are wrong* from *the platform is down*
from *this application cannot reach it* — three things that, from inside a
failing checkout, look identical.

**Use a shared cache store.** The SDK caches the 15-minute access token,
and the token endpoint is throttled at twenty a minute **by IP** because
it is the only one reachable without a token. An application caching in
the `array` driver holds one token per PHP process: fine, until a deploy
restarts twelve workers at once. Set `CTPL_PAYMENTS_TOKEN_STORE=redis` (or
your shared store) if it is not your default.

---

## Three rules that will save you a support ticket

**1. A browser redirect is not confirmation of payment.** The customer
reaching your success URL means they finished the checkout flow. It does
not mean money moved, and the URL is under their control. `Payments::confirm()`
asks the platform, which asks the gateway. Nothing in this package infers
an outcome from where a browser went.

**2. `UnresolvedAttempt` means stop, not retry.** It means an earlier
attempt timed out and the platform does not know whether the customer was
charged. It is a `409`, and the instinct on a 409 is to back off and try
again — which is how somebody pays twice. Every exception in this package
answers `retryable()`; that one answers `false`, and `RateLimited` answers
`true`. **Ask the exception, not the status code.**

**3. Amounts are integers in minor units.** `Money` is the only way to
express one here, so a float never reaches the wire:

```php
Money::rupees(1250.50);   // 125050 — converted as a string, not 1250.50 * 100
Money::minor(125050);     // when it is already stored correctly
Money::rupees('1250.505') // throws. Rounding somebody's money is worse than refusing it
```

`(int) (19.99 * 100)` is `1998`. Which amounts a binary float truncates is
not predictable by looking at them, and a platform that is one paisa out
on some and not others is one whose settlement never reconciles.

---

## Taking a payment

`pay()` is `createOrder()` and `openAttempt()` together, which is the
usual path — an order with no attempt is an order nobody can pay.

```php
$order = Payments::pay(
    reference: "BOOKING-{$booking->id}",   // yours, unique per order
    amount: Money::rupees(1250),
    customer: new Customer(...),           // optional; prefills the gateway's checkout
    method: PaymentMethodType::Upi,        // optional; omit and the customer chooses
    metadata: ['booking_id' => $booking->id],
);
```

### Getting the customer to the gateway

There are five checkout types because gateways genuinely differ, and which
one a payment gets is decided by routing at the moment the attempt opens.
Reading `redirect_url` and hoping is how an integration breaks in front of
a paying customer.

```php
return CheckoutResponder::respond($order);
```

handles `redirect` and `form_post` — the two a server can complete on its
own. The other three (`sdk`, `intent`, `qr`) need JavaScript, and
`respond()` **refuses** them rather than sending the customer somewhere
that cannot take their money:

```php
if (CheckoutResponder::isServerDriven($order)) {
    return CheckoutResponder::respond($order);
}

return view('checkout', ['checkout' => CheckoutResponder::payloadFor($order)]);
```

That payload is safe to put in a page: the session token is scoped to one
payment and expires with it. It is **not** your API token.

### Confirming it

```php
$order = Payments::confirm($order);              // polls to a terminal state
$order = Payments::confirm($order, timeoutSeconds: 5);   // or don't wait long
$order = Payments::order($orderId);              // or just look, once
```

`confirm()` does **not** throw on a declined card. A decline is an
outcome, not an error, and code that catches an exception to say "your
card was declined" reads worse than code that asks:

```php
match (true) {
    $order->isPaid()               => $booking->markPaid(),
    $order->status->isFailure()    => $booking->markFailed($order->failureMessage()),
    default                        => $booking->awaitConfirmation(),   // still unsettled
};
```

In a web request give it a short budget or none, and wait in a queued job.
A customer holding a spinner for ninety seconds is worse than an email two
minutes later.

> `UNKNOWN` is **not** a failure. It means the gateway never said what
> happened, the customer may well have been charged, and the platform is
> resolving it. `PaymentStatus::Unknown->label()` is "Being confirmed",
> never "Failed", for exactly this reason.

---

## Events, rather than polling

Better than polling: register a webhook URL with the platform and let it
tell you. The SDK hosts the endpoint, verifies the signature before
anything parses the body, and dispatches Laravel events.

```dotenv
CTPL_PAYMENTS_WEBHOOKS_ENABLED=true
CTPL_PAYMENTS_WEBHOOK_SECRET=whsec_...
```

```php
// EventServiceProvider
protected $listen = [
    \Ctpl\Payments\Webhooks\Events\PaymentCaptured::class => [MarkBookingPaid::class],
    \Ctpl\Payments\Webhooks\Events\PaymentFailed::class   => [NotifyCustomer::class],

    // Or one listener for everything, including event types this
    // package predates:
    \Ctpl\Payments\Webhooks\Events\PaymentEventReceived::class => [HandleAnyPaymentEvent::class],
];
```

```php
public function handle(PaymentCaptured $e): void
{
    $booking = Booking::where('reference', $e->event->externalReference())->firstOrFail();

    // An event is a prompt to go and look, not the answer. A stale one can
    // arrive after a refund; the order is the only thing that is current.
    if (Payments::order($e->event->paymentOrderId())->isPaid()) {
        $booking->markPaid();
    }
}
```

The endpoint is **off until you switch it on** — an endpoint that exists
by default is one you do not know you are exposing. With no secret
configured it refuses rather than accepting unsigned events: an unsigned
payment notification cannot be told from a forged one.

**Duplicates are normal.** The platform retries deliveries it is not sure
arrived; the same event will come twice. The SDK remembers event ids for a
day and acknowledges repeats without dispatching — but that is a
convenience, not a guarantee. **Write idempotent listeners.** Do the work
against the payment order id, not against "I received an event".

A listener that throws becomes a `500` deliberately, so the platform
retries. Catching it would turn a failure into a `200`, which tells the
platform to stop — and the event would be lost by the code trying to be
careful about it.

---

## Refunds

```php
$refund = Payments::refund($order, reason: 'Appointment cancelled');
$refund = Payments::refund($order, Money::rupees(500), 'Partial — late cancellation');
```

Full and partial refunds need **different scopes**: a credential that may
undo a booking is not necessarily one that may return an arbitrary sum.

Raising a refund is a request, not a payout — the money moves on the
gateway's schedule. `$refund->state->isComplete()` is the only safe basis
for telling a customer their money is back.

---

## Payment links

A page you can send by email, SMS or WhatsApp. Each press of Pay creates
its own order behind the scenes.

```php
$link = Payments::createLink(
    title: 'Consultation fee — Dr Rao',
    maxUses: 1,                                  // required: it decides what kind of link this is
    amount: Money::rupees(1250),
    customer: new Customer(...),                 // required when maxUses is 1
    reference: "INV-{$invoice->id}",
);

$link->url;   // the payable address — returned ONLY here. Store it now.
```

`maxUses: 1` is an invoice for one named person and needs their details.
Anything else is a link for whoever opens it, and a customer is **refused**
— everyone who opens it is a different person and enters their own details
at payment time. The SDK refuses both mistakes before a request is made.

---

## Testing your own code

```php
use Ctpl\Payments\Facades\Payments;

public function test_a_booking_is_marked_paid(): void
{
    $fake = Payments::fake();

    $this->post('/bookings/1/pay')->assertRedirect();

    $fake->assertOrderCreated('BOOKING-1')->assertAttemptOpened();
}
```

No network, no credentials, no orchestrator. More usefully, the fake can
be told to behave as the platform does on a bad day:

```php
Payments::fake()->willBeDeclined();
Payments::fake()->willExpire();
Payments::fake()->willBeUnresolved();      // opening an attempt throws UnresolvedAttempt
Payments::fake()->willFailWith(new RateLimited('...'));
```

**Write the `willBeUnresolved()` test.** The instinctive handling of that
409 — catch it and retry — is the one that charges a customer twice, and
nothing except a test will tell you your code does it.

Assertions: `assertOrderCreated()`, `assertAttemptOpened()`,
`assertConfirmed()`, `assertRefunded()`, `assertNothingCharged()`,
`assertCalled()`, `assertNotCalled()`.

---

## Errors

Every failure is a `PaymentsException` carrying `type`, `status`,
`details` and `correlationId`. **Log the correlation id** — it is what the
platform's operators search by.

```php
try {
    $order = Payments::openAttempt($order);
} catch (UnresolvedAttempt $e) {
    // Do NOT retry. Wait instead.
    ConfirmPayment::dispatch($e->paymentOrderId())->delay(now()->addMinutes(2));
} catch (PaymentsException $e) {
    Log::error('payment failed', ['type' => $e->type, 'correlation' => $e->correlationId]);

    if ($e->retryable()) {
        $this->release($e->retryAfter() ?? 30);
    }
}
```

| Exception | `type` | Retryable |
|---|---|---|
| `UnresolvedAttempt` | `unresolved_attempt` | **no — never** |
| `RequestInProgress` | `request_in_progress` | yes |
| `IdempotencyKeyConflict` | `idempotency_key_conflict` | no |
| `RateLimited` | `rate_limited` | yes, after `retryAfter()` |
| `InvalidClient` | `invalid_client` | no |
| `InsufficientScope` | `insufficient_scope` | no |
| `NotFound` | `not_found` | no — *or it is not yours; the two are deliberately indistinguishable* |
| `ValidationFailed` | `validation_failed` | no |
| `OrderNotPayable` | `order_not_payable` | no |
| `RefundNotPermitted` | `refund_not_permitted` | no |
| `PaymentLinkInvalid` | `payment_link_invalid` | no |
| `ServiceUnavailable` | `no_eligible_gateway` | yes, with real backoff |
| `ServiceUnavailable` | `gateway_unavailable`, `routing_misconfigured` | no — the platform's to fix |
| `PaymentsException` | anything this package predates | **no** |

That last row is the point of the hierarchy: an unrecognised payment
failure is one to stop on, not one to hammer.

### Idempotency

Every write carries an `Idempotency-Key`, derived from the operation —
`BOOKING-4102:order`, `po_xxx:refund:full`. Pass your own if you have a
better handle. The SDK makes it a **required argument** on the low-level
client rather than an optional header, so the omission is caught in your
editor rather than by the platform.

Derive it from the operation, never from a random generator: a random key
makes every retry a new payment, which is the exact failure the header
exists to prevent.

---

## Verifying it

```bash
vendor/bin/phpunit                                  # unit + feature, no network
tools/verify_sdk.sh /path/to/ctpl-app               # ...and against a real orchestrator
```

The default suite runs against `Http::fake()`, which answers with bodies
this package wrote — it proves the SDK is consistent with itself and
nothing about whether the platform agrees. `tools/verify_sdk.sh` issues a
real credential, runs the platform's confirmation sweep in the background,
and takes a real payment through to captured, refunds it, makes a link and
withdraws it.

The integration suite is the one place the platform URL is not the
constant — `CTPL_PAYMENTS_BASE_URL_OVERRIDE` points it at a local install,
and `Config` honours that **only** when the application environment is
`local` or `testing`. In anything else the constant wins and the variable
is ignored, so the escape hatch that makes the suite possible cannot be
used to redirect a real application's payments.

It also checks the webhook signature against the **platform's own signer**,
in the platform's own process. Signing and verifying with the same class
proves only that the class agrees with itself; if the scheme were wrong,
both sides would be wrong identically and every test would pass until the
first real delivery was refused.

---

## What this package will not do

**It will not tell you a payment succeeded without asking the platform.**
No inference from a redirect, no trusting a webhook body, no reading a
gateway's word at initiation. The platform holds that line internally and
this package holds it at the edge.

**It will not let an application choose where payments go.** The URL is a
constant, not a setting. See the install section.

**It will not expose a gateway.** No gateway payment id, no gateway order
id, no branching on which provider took the money. An integration that
said "if Razorpay then…" is one the platform can no longer route away
from, and routing away from a struggling gateway is the point of the whole
thing.

**It will not retry a payment it cannot prove is safe to retry.** See the
table above.
