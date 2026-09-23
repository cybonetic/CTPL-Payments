<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Where the orchestrator is — and it is not here
    |--------------------------------------------------------------------------
    |
    | There is deliberately no setting for it. The URL is a constant in the
    | package (`Ctpl\Payments\Client\Config::PLATFORM_URL`), because an
    | application does not get to choose where its payments go: a base URL
    | that anything able to write a line into `.env` can change is one that a
    | leaked deploy credential can point at a host of its choosing, silently,
    | taking every payment and every access token with it.
    |
    | Staging is not a different host. Which gateway accounts a payment may
    | use is decided by the environment of the APPLICATION your credential
    | belongs to, on the platform side — so a staging credential cannot reach
    | production money, and that separation is the platform's rather than
    | something each application has to arrange correctly.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Issued per application from the operator portal. The secret is shown
    | once and cannot be retrieved again.
    |
    */

    'client_id' => env('CTPL_PAYMENTS_CLIENT_ID'),
    'client_secret' => env('CTPL_PAYMENTS_CLIENT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | The access token
    |--------------------------------------------------------------------------
    |
    | Tokens last fifteen minutes. The SDK caches one and refreshes it on
    | expiry, because the token endpoint is the only one reachable without a
    | token and is therefore throttled hard, by IP — an application fetching
    | one per request will be rate-limited, and will take its neighbours on
    | the same egress address down with it.
    |
    | `store` is a cache store name. Use one that is shared between your web
    | processes; the array driver means every process holds its own token and
    | you are back to fetching one per boot.
    |
    | `skew` is how long before real expiry the SDK treats a token as stale.
    |
    */

    'token' => [
        'store' => env('CTPL_PAYMENTS_TOKEN_STORE'),
        'key' => 'ctpl-payments:token',
        'skew_seconds' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | `retries` applies ONLY to requests the SDK knows are safe to repeat:
    | reads, and writes whose idempotency key makes a repeat return the
    | original response. It never retries a 409 — see the exception classes,
    | where `retryable()` is a decision made per error type rather than per
    | status code.
    |
    */

    'timeout' => (int) env('CTPL_PAYMENTS_TIMEOUT', 15),
    'connect_timeout' => (int) env('CTPL_PAYMENTS_CONNECT_TIMEOUT', 5),
    'retries' => (int) env('CTPL_PAYMENTS_RETRIES', 2),
    'retry_base_ms' => 200,

    /*
    |--------------------------------------------------------------------------
    | Confirming a payment
    |--------------------------------------------------------------------------
    |
    | Used by `Payments::confirm()`, which polls until the payment reaches a
    | terminal state. Defaults are for a synchronous "wait for the customer to
    | finish" path; a queued job should pass its own, longer, budget.
    |
    */

    'confirm' => [
        'timeout_seconds' => (int) env('CTPL_PAYMENTS_CONFIRM_TIMEOUT', 90),
        'initial_delay_ms' => 500,
        'max_delay_ms' => 4000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Coming back to the page the payment started on
    |--------------------------------------------------------------------------
    |
    | Off by default. Switched on, every payment this SDK creates carries
    | the URL of the page that created it, and the platform returns the
    | customer there instead of to the one landing route configured for
    | the whole application.
    |
    | It needs the matching setting in the operator portal: "Return to
    | origin", with the domains your payments may start from recorded
    | beside it. Without those the platform cannot tell your pages from
    | anybody else's and refuses the URL with a 422 on the first call —
    | which is the right moment to find out, rather than from a customer
    | who has paid and gone missing.
    |
    | Only https URLs are sent. A customer who has just entered a card
    | number is not being redirected over plain http.
    |
    | Pass `returnUrl:` to `pay()` or `createOrder()` to override it for
    | one payment — a modal, a queued job, a retry driven by a webhook.
    |
    */

    'return_to_origin' => (bool) env('CTPL_PAYMENTS_RETURN_TO_ORIGIN', false),

    /*
    |--------------------------------------------------------------------------
    | Receiving events
    |--------------------------------------------------------------------------
    |
    | The platform POSTs signed events to a URL you register with it. The SDK
    | can host that endpoint for you: set `enabled` and give it the signing
    | secret from the subscriber's configuration.
    |
    | `tolerance_seconds` is how much clock skew a delivery may carry. Too
    | generous and a captured request can be replayed at leisure; too tight
    | and a slow queue on the platform's side looks like an attack. Five
    | minutes is what the platform itself allows.
    |
    | `dedupe_ttl_seconds` is how long a delivered event id is remembered.
    | The platform retries deliveries it is not sure arrived, so the same
    | event WILL arrive twice; your listeners must be idempotent regardless,
    | and this takes the common case off their hands.
    |
    */

    'webhooks' => [
        'enabled' => (bool) env('CTPL_PAYMENTS_WEBHOOKS_ENABLED', false),
        'path' => env('CTPL_PAYMENTS_WEBHOOKS_PATH', 'ctpl/payments/events'),
        'middleware' => ['api'],
        'secret' => env('CTPL_PAYMENTS_WEBHOOK_SECRET'),
        'tolerance_seconds' => 300,
        'dedupe_ttl_seconds' => 86400,
        'dedupe_store' => env('CTPL_PAYMENTS_DEDUPE_STORE'),
    ],

];
