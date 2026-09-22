<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Where the orchestrator is
    |--------------------------------------------------------------------------
    |
    | The host only — the SDK adds `/api/v1`. Point a staging application at a
    | staging credential rather than at a different host: which gateway
    | accounts a payment may use is decided by the APPLICATION's environment
    | on the platform side, so a staging credential cannot reach production
    | money even against the production host.
    |
    */

    'base_url' => env('CTPL_PAYMENTS_URL', 'https://pay.cybonetic.com'),

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
